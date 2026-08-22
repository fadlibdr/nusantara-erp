<?php

namespace Modules\Crm\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\ContractTermin;

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

            $order = new ContractChangeOrder(Arr::only($data, [
                'contract_id', 'change_date', 'title', 'description',
                'reason', 'change_type', 'value_change', 'customer_ref',
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

        $order->fill(Arr::only($data, [
            'change_date', 'title', 'description', 'reason', 'change_type', 'value_change', 'customer_ref',
        ]));

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

        return [
            'contract' => $contract->code,
            'original_value' => (float) ($contract->original_value ?? $contract->value),
            'current_value' => (float) $contract->value,
            'net_change' => round((float) $approved->sum('value_change'), 2),
            'additions' => round((float) $approved->where('value_change', '>=', 0)->sum('value_change'), 2),
            'reductions' => round((float) $approved->where('value_change', '<', 0)->sum('value_change'), 2),
            'change_order_count' => $approved->count(),
            'billed_to_date' => $this->billedTotal($contract),
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

    private function assertEditable(ContractChangeOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw new LogicException(
                "Perubahan {$order->code} berstatus {$order->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
