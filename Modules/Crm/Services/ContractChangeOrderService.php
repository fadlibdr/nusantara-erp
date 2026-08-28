<?php

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Crm\Enums\ChangeOrderType;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\ContractTermin;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Services\ProjectService;

/**
 * Change orders against a signed contract.
 *
 * The contract row is untouched until a change order is APPROVED, and then its
 * value is adjusted rather than replaced — crm_contracts.original_value keeps
 * what was signed, so "what did we agree to" and "what is it worth now" stay
 * two separate questions.
 *
 * Existing termins are never modified. A schedule whose early milestones are
 * already invoiced cannot be re-spread without restating what was billed, so
 * added scope is billed through new termins rather than by moving percentages.
 *
 * ADDENDUM WAKTU (P0-B) rides the same document: a CCO of type 'waktu' moves
 * the contract's END DATE and nothing else. Time and money never move on the
 * same sheet — value_change is required to be exactly 0 — because a combined
 * addendum would make "what did this CCO do" a two-part answer no register
 * column can hold. new_end_date is computed at approval from the contract's
 * CURRENT end_date, so sequential addenda stack; original_end_date is written
 * once by the first approval, mirroring original_value.
 */
class ContractChangeOrderService
{
    public function create(array $data): ContractChangeOrder
    {
        return DB::transaction(function () use ($data): ContractChangeOrder {
            $contract = Contract::query()->findOrFail($data['contract_id']);

            // A draft or rejected contract should be EDITED, not amended: there
            // is nothing signed to amend, and a change order against it would
            // be a second way to set the same number.
            if ($contract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Kontrak {$contract->code} berstatus {$contract->status->value}. "
                    .'Pekerjaan tambah-kurang hanya berlaku atas kontrak yang sudah disetujui — '
                    .'ubah nilainya langsung selama masih draf.'
                );
            }

            $this->assertComputedDateNotInput($data);

            if ($this->typeOf($data) === ChangeOrderType::Waktu) {
                // The screen may simply omit the money field on a time sheet.
                $data['value_change'] = $data['value_change'] ?? 0;
                $this->assertTimeAddendum($contract, (float) $data['value_change'], $data['days_change'] ?? null);
            } elseif (($data['days_change'] ?? null) !== null) {
                throw new LogicException(
                    'days_change hanya bermakna pada addendum waktu — perubahan nilai tidak menggeser tanggal; '
                    .'waktu dan nilai diubah lewat dua CCO terpisah.'
                );
            }

            $order = new ContractChangeOrder(Arr::only($data, [
                'contract_id', 'change_date', 'title', 'description',
                'reason', 'change_type', 'value_change', 'days_change', 'customer_ref',
            ]));

            $order->ppn_change = $this->ppnFor($contract, (float) $data['value_change']);
            $order->status = DocumentStatus::Draft;
            $order->save();

            return $order->refresh();
        });
    }

    public function update(ContractChangeOrder $order, array $data): ContractChangeOrder
    {
        $this->assertEditable($order);
        $this->assertComputedDateNotInput($data);

        $order->fill(Arr::only($data, [
            'change_date', 'title', 'description', 'reason', 'change_type', 'value_change', 'days_change', 'customer_ref',
        ]));

        // The RESULTING combination is what must hold — an edit that switches
        // a draft to 'waktu' must also zero its value, and one that switches
        // away must clear days_change (send null; 'prohibited' allows empty).
        if ($order->change_type === ChangeOrderType::Waktu) {
            $order->days_change = $order->days_change === null ? null : (int) $order->days_change;
            $this->assertTimeAddendum($order->contract, (float) $order->value_change, $order->days_change);
        } elseif ($order->days_change !== null) {
            throw new LogicException(
                'days_change hanya bermakna pada addendum waktu — kosongkan saat mengubah jenis perubahan.'
            );
        }

        if (array_key_exists('value_change', $data)) {
            $order->ppn_change = $this->ppnFor($order->contract, (float) $data['value_change']);
        }

        $order->save();

        return $order->refresh();
    }

    public function delete(ContractChangeOrder $order): void
    {
        $this->assertEditable($order);

        $order->delete();
    }

    /**
     * Approve, and move the contract value.
     *
     * The guard that matters is the floor: a reduction may not take the contract
     * below what has already been invoiced against it. Allowing that would leave
     * a contract worth less than the sum of its own approved invoices, which no
     * report can present sensibly and no auditor will accept.
     */
    public function approve(ContractChangeOrder $order, User $by, ?string $note = null): ContractChangeOrder
    {
        return DB::transaction(function () use ($order, $by, $note): ContractChangeOrder {
            /** @var ContractChangeOrder $order */
            $order = ContractChangeOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            /** @var Contract $contract */
            $contract = Contract::query()->whereKey($order->contract_id)->lockForUpdate()->firstOrFail();

            if ($order->change_type === ChangeOrderType::Waktu) {
                return $this->approveTimeAddendum($order, $contract, $by, $note);
            }

            $change = round((float) $order->value_change, 2);
            $newValue = round((float) $contract->value + $change, 2);
            $billed = $this->billedTotal($contract);

            if ($newValue < $billed - 0.01) {
                throw new LogicException(sprintf(
                    'Nilai kontrak setelah perubahan (%s) lebih kecil daripada yang sudah ditagihkan (%s).',
                    number_format($newValue, 2, ',', '.'),
                    number_format($billed, 2, ',', '.'),
                ));
            }

            if ($newValue < 0) {
                throw new LogicException('Nilai kontrak tidak boleh menjadi negatif.');
            }

            $order->approve($by, $note);

            // Recorded once, on the first approved change order, so the column
            // means "the value this contract started at" rather than a copy that
            // every later amendment overwrites.
            $original = $contract->original_value ?? $contract->value;

            $ppnRate = (float) $contract->ppn_rate;
            $ppn = round($newValue * $ppnRate / 100, 2);

            $contract->forceFill([
                'original_value' => $original,
                'value' => $newValue,
                'ppn_amount' => $ppn,
                'total_with_ppn' => round($newValue + $ppn, 2),
            ])->save();

            // The project copied this value ONCE at createFromContract, and the
            // project workspace tiles ("Nilai kontrak", "Retensi ditahan") read
            // that copy — without this line the project team keeps seeing the
            // signing-day number while finance and revenue recognition read
            // crm_contracts.value: two versions of the truth (temuan #74).
            // DB::table, not the Projects model, for the same reason
            // TerminBillingService::achievedMilestones() reads prj_milestones
            // raw: CRM writing one project fact must not make the CRM module
            // depend on Projects at runtime. Project BASELINES are left alone
            // on purpose — they are historical snapshots, and the gap against
            // them is exactly what EVM reports as contract-value deviation.
            DB::table('prj_projects')
                ->where('contract_id', $contract->id)
                ->whereNull('deleted_at')
                ->update([
                    'contract_value' => $newValue,
                    'updated_at' => now(),
                ]);

            return $order->refresh();
        });
    }

    /**
     * Approve an addendum waktu: shift the contract's end date, and the
     * project's copy of it, by days_change.
     *
     * Runs inside approve()'s transaction, on rows already re-read under lock.
     *
     * new_end_date is computed HERE, from the end_date the locked row carries
     * NOW — which is what makes sequential addenda stack: the second one
     * builds on the date the first already shifted, never on the signing-day
     * date. It is stamped onto the CCO row so the printed register and the kop
     * quote a recorded fact instead of re-deriving one.
     *
     * The project side goes through ProjectService::shiftEndDateForContract —
     * its own locked re-read, and the warranty/closed gate lives there, beside
     * the write it protects. A refusal rolls this whole approval back.
     *
     * There is no cancel path for a CCO (Approvable knows submit, approve and
     * reject; DocumentStatus::Cancelled belongs to Finance documents), so an
     * approved addendum is final — the undo instrument is a counter-addendum
     * with negative days, which this method accepts like any other.
     */
    private function approveTimeAddendum(ContractChangeOrder $order, Contract $contract, User $by, ?string $note): ContractChangeOrder
    {
        // Defense in depth: create/update refuse these combinations, but this
        // method is the one that writes, and rows can predate the rules.
        $this->assertTimeAddendum($contract, (float) $order->value_change, $order->days_change);

        $days = (int) $order->days_change;
        $newEnd = $contract->end_date->copy()->addDays($days);

        if ($contract->start_date !== null && $newEnd->lt($contract->start_date)) {
            throw new LogicException(sprintf(
                'Pengurangan %d hari menggeser tanggal selesai menjadi %s — mendahului tanggal mulai kontrak (%s).',
                abs($days),
                $newEnd->toDateString(),
                $contract->start_date->toDateString(),
            ));
        }

        app(ProjectService::class)->shiftEndDateForContract($contract->id, $newEnd);

        $order->approve($by, $note);
        $order->forceFill(['new_end_date' => $newEnd->toDateString()])->save();

        $contract->forceFill([
            // Recorded once, on the first approved time addendum, mirroring
            // original_value: "when did we promise to finish" survives every
            // later shift.
            'original_end_date' => ($contract->original_end_date ?? $contract->end_date)->toDateString(),
            'end_date' => $newEnd->toDateString(),
        ])->save();

        return $order->refresh();
    }

    public function reject(ContractChangeOrder $order, User $by, ?string $note = null): ContractChangeOrder
    {
        $order->reject($by, $note);

        return $order->refresh();
    }

    /**
     * Jadwalkan penagihan nilai tambah: SATU termin baru senilai value_change
     * (temuan #14).
     *
     * This is the path the docblock above has promised all along — 'added
     * scope is billed through new termins' — and which did not exist: the
     * approved contract's schedule is frozen, so the added value could only be
     * billed by a manual invoice with no due_date, no billed_at, and no row in
     * the antrean siap tagih. A wizard step after approval, never automatic:
     * WHEN the added scope becomes billable is a commercial decision the
     * approval does not carry.
     *
     * PERSEN vs JUMLAH — how the new termin fits the 'total persen = 100'
     * rule honestly: that rule belongs to the SIGNED schedule. It is enforced
     * against the signed value at create/update/activate and never re-checked
     * after approval, while billing reads the AMOUNT column whenever it is set
     * (ArInvoiceService::createFromTermin falls back to percent only at
     * amount = 0). So the CCO termin carries percent 0 and amount =
     * value_change: the signed percents keep telling their 100%-of-signed
     * story untouched, and the schedule's amounts cover the CURRENT value
     * again — signed termins sum to the signed value, each scheduled CCO adds
     * exactly its change. Restating the percents instead would re-spread a
     * schedule whose early termins are already invoiced, which is the one
     * thing this document family promises never to do.
     *
     * Idempotent via the RE-READ: the termin_id stamp is checked on a fresh
     * row inside the transaction (lockForUpdate is a no-op on SQLite), so the
     * double-clicked wizard schedules once and the second click is refused —
     * not two termins both worth the same added value.
     */
    public function scheduleTermin(ContractChangeOrder $order, array $data): ContractTermin
    {
        return DB::transaction(function () use ($order, $data): ContractTermin {
            /** @var ContractChangeOrder $order */
            $order = ContractChangeOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Perubahan {$order->code} berstatus {$order->status->value}; hanya perubahan yang "
                    .'sudah disetujui yang nilainya masuk kontrak — dan hanya nilai itu yang bisa dijadwalkan.'
                );
            }

            if ($order->change_type === ChangeOrderType::Waktu) {
                throw new LogicException(
                    "Addendum waktu {$order->code} tidak membawa nilai — tidak ada yang dijadwalkan untuk ditagih."
                );
            }

            $change = round((float) $order->value_change, 2);

            if ($change <= 0) {
                throw new LogicException(
                    "Perubahan {$order->code} bernilai {$change}; pekerjaan kurang mengurangi sisa yang "
                    .'boleh ditagih, bukan menambah jadwal — tidak ada termin baru untuk dijadwalkan.'
                );
            }

            if ($order->termin_id !== null) {
                /** @var ContractTermin|null $existing */
                $existing = ContractTermin::query()->find($order->termin_id);

                throw new LogicException(
                    "Nilai tambah {$order->code} sudah dijadwalkan sebagai termin"
                    .($existing !== null ? " {$existing->termin_no} (\"{$existing->name}\")" : ' lain')
                    .' — satu perubahan, satu termin.'
                );
            }

            /** @var Contract $contract */
            $contract = Contract::query()->whereKey($order->contract_id)->lockForUpdate()->firstOrFail();

            if ($contract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "Kontrak {$contract->code} berstatus {$contract->status->value}; "
                    .'termin hanya dijadwalkan pada kontrak yang masih berjalan.'
                );
            }

            $termins = $contract->termins()->get();

            // A fully billed schedule is a finished billing story: its closing
            // termin (BAST/retensi) is invoiced, and retention release plus
            // warranty anchor to it. A termin appended AFTER that reopens the
            // story — scope agreed at that point is billed as a manual invoice
            // on the same contract instead, and stays visible in billed-vs-
            // value through ContractChangeOrderService::summaryFor().
            if ($termins->isNotEmpty() && $termins->every(fn (ContractTermin $termin): bool => $termin->billed_at !== null)) {
                throw new LogicException(
                    "Seluruh termin kontrak {$contract->code} sudah ditagihkan — jadwal penagihannya selesai; "
                    ."tagih nilai tambah {$order->code} lewat invoice manual atas kontrak yang sama."
                );
            }

            $termin = $contract->termins()->create([
                'termin_no' => (int) $termins->max('termin_no') + 1,
                // 100-char column; the code is what must survive truncation,
                // so it leads.
                'name' => Str::limit((string) ($data['name'] ?? "Pekerjaan tambah {$order->code}"), 100, ''),
                'percent' => 0,
                'amount' => $change,
                'billing_condition' => "Pekerjaan tambah-kurang {$order->code} — {$order->title}",
                'is_retention' => false,
                'due_date' => $data['due_date'],
            ]);

            $order->forceFill(['termin_id' => $termin->id])->save();

            return $termin;
        });
    }

    /**
     * What the contract is worth now, and how it got there.
     */
    public function summaryFor(Contract $contract): array
    {
        $approved = ContractChangeOrder::query()
            ->where('contract_id', $contract->id)
            ->where('status', DocumentStatus::Approved->value)
            ->get();

        $timeAddenda = $approved->where('change_type', ChangeOrderType::Waktu);

        return [
            'contract' => $contract->code,
            'original_value' => (float) ($contract->original_value ?? $contract->value),
            'current_value' => (float) $contract->value,
            'net_change' => round((float) $approved->sum('value_change'), 2),
            'additions' => round((float) $approved->where('value_change', '>=', 0)->sum('value_change'), 2),
            'reductions' => round((float) $approved->where('value_change', '<', 0)->sum('value_change'), 2),
            'change_order_count' => $approved->count(),
            'billed_to_date' => $this->billedTotal($contract),
            // The time story, same shape as the value story: what was signed,
            // what stands now, how it got there (P0-B).
            'original_end_date' => ($contract->original_end_date ?? $contract->end_date)?->toDateString(),
            'current_end_date' => $contract->end_date?->toDateString(),
            'net_days_change' => (int) $timeAddenda->sum('days_change'),
            'time_addendum_count' => $timeAddenda->count(),
        ];
    }

    /** PPN follows the contract's own rate — 0 for a non-PKP customer. */
    private function ppnFor(Contract $contract, float $valueChange): float
    {
        $rate = (float) ($contract->ppn_rate ?? Erp::float('tax.ppn_rate', 11.0));

        return round($valueChange * $rate / 100, 2);
    }

    private function billedTotal(Contract $contract): float
    {
        return round((float) DB::table('fin_ar_invoices')
            ->where('contract_id', $contract->id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('deleted_at')
            ->sum('dpp'), 2);
    }

    private function typeOf(array $data): ChangeOrderType
    {
        $raw = $data['change_type'] ?? null;

        if ($raw instanceof ChangeOrderType) {
            return $raw;
        }

        // Absent means tambah_kurang, the column default — same reading the
        // controller gives older clients.
        return ChangeOrderType::tryFrom((string) ($raw ?? '')) ?? ChangeOrderType::TambahKurang;
    }

    /**
     * What must hold before a time addendum may exist or be written.
     *
     * value_change exactly 0: time and money never move on the same sheet.
     * days_change signed and non-zero: a pengurangan waktu is real; a zero-day
     * addendum is a note. And the contract must HAVE an end date — a shift
     * without a basis is not computable, and inventing one here would be this
     * service deciding the contract's schedule on its own.
     *
     * The project gate (masa pemeliharaan / ditutup) is checked here too so a
     * doomed draft is refused at entry; the AUTHORITATIVE copy of that gate
     * lives in ProjectService::shiftEndDateForContract, under lock, beside the
     * write — a status change between create and approve is caught there.
     */
    private function assertTimeAddendum(Contract $contract, float $valueChange, mixed $daysChange): void
    {
        if (round($valueChange, 2) !== 0.0) {
            throw new LogicException(
                'Addendum waktu tidak memindahkan nilai — value_change wajib 0; '
                .'perubahan nilai dicatat sebagai CCO tambah-kurang tersendiri.'
            );
        }

        if ((int) ($daysChange ?? 0) === 0) {
            throw new LogicException(
                'Addendum waktu tanpa hari bukan perubahan — days_change wajib diisi dan tidak boleh 0 '
                .'(negatif berarti pengurangan waktu).'
            );
        }

        if ($contract->end_date === null) {
            throw new LogicException(
                "Kontrak {$contract->code} tidak memiliki tanggal selesai — addendum waktu tidak punya dasar untuk digeser."
            );
        }

        $status = $contract->project?->status;

        if (in_array($status, [ProjectStatus::Warranty, ProjectStatus::Closed], true)) {
            throw new LogicException(sprintf(
                'Proyek %s berstatus %s; addendum waktu hanya berlaku atas pekerjaan yang masih berjalan — '
                .'perpanjangan setelah serah terima adalah instrumen lain.',
                $contract->project->code,
                $status->label(),
            ));
        }
    }

    /**
     * new_end_date is DERIVED — contract.end_date + days_change, computed at
     * approval — and accepting it as input would let two pending addenda both
     * promise dates that ignore each other, the exact mistake
     * changeOrderValues() refuses for money.
     */
    private function assertComputedDateNotInput(array $data): void
    {
        if (($data['new_end_date'] ?? null) !== null) {
            throw new LogicException(
                'new_end_date dihitung sistem saat addendum disetujui — tanggal selesai kontrak berjalan '
                .'+ days_change — bukan diinput.'
            );
        }
    }

    private function assertEditable(ContractChangeOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw new LogicException(
                "Perubahan {$order->code} berstatus {$order->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
