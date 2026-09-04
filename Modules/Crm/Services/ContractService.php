<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;

class ContractService
{
    public function create(array $data): Contract
    {
        $termins = Arr::pull($data, 'termins', []);
        // Pulled out of mass-assignment: stored only when it explains a
        // difference from the linked quotation (applyValueChangeReason).
        $reason = Arr::pull($data, 'value_change_reason');
        $this->assertTerminPercentsSumTo100($termins);

        return DB::transaction(function () use ($data, $termins, $reason): Contract {
            $data['ppn_rate'] = $data['ppn_rate'] ?? Erp::float('tax.ppn_rate', 11.0);
            $data['retention_pct'] = $data['retention_pct'] ?? Erp::float('projects.default_retention_pct', 5.0);

            $contract = new Contract(Arr::except($data, ['code', 'status']));
            $contract->status = DocumentStatus::Draft;
            $this->applyTaxTotals($contract);
            $this->applyValueChangeReason($contract, $reason);
            $contract->save(); // HasDocumentNumber fills the CTR code

            $this->syncTermins($contract, $termins);

            return $contract->load('termins', 'customer');
        });
    }

    public function update(Contract $contract, array $data): Contract
    {
        $this->assertEditable($contract);

        return DB::transaction(function () use ($contract, $data): Contract {
            $termins = Arr::pull($data, 'termins');
            // Absent key = "no new claim": a line-only edit keeps the stored
            // reason; a sent value (even null) replaces it and is judged anew.
            $reason = array_key_exists('value_change_reason', $data)
                ? Arr::pull($data, 'value_change_reason')
                : $contract->value_change_reason;

            $contract->fill(Arr::except($data, ['code', 'status']));
            $this->applyTaxTotals($contract);
            $this->applyValueChangeReason($contract, $reason);
            $contract->save();

            if (is_array($termins)) {
                $this->assertTerminPercentsSumTo100($termins);

                if ($contract->termins()->whereNotNull('billed_at')->exists()) {
                    throw new LogicException("Contract {$contract->code} has billed termins; the schedule can no longer be replaced.");
                }

                $this->syncTermins($contract, $termins);
            } elseif ($contract->wasChanged('value')) {
                // Contract value changed without a new schedule: re-spread existing percents.
                $this->respreadTerminAmounts($contract);
            }

            return $contract->load('termins', 'customer');
        });
    }

    /**
     * "Buat kontrak" / "Lengkapi kontrak" on a won quotation (T3.6, ANALISIS-PROSES A1).
     *
     * Production, 4 Sep 2026: the only route from a won offer to its contract
     * was the contract form, blank — QTN/2026/VIII/0008 Rp 2,04 M was retyped
     * as CTR/2026/VIII/0004 Rp 1,84 M with no quotation_id and no word on the
     * Rp 200 jt between them. Here the quotation supplies what it knows
     * (customer, title, scope, PPN rate, value = DPP), the caller supplies
     * what it cannot (dates, the customer's number, the termin schedule — a
     * quotation carries no termins, and contracts carry no line items, so the
     * offer's lines are carried as its value, not copied), and a value other
     * than the DPP has to be explained (applyValueChangeReason).
     *
     * The mark-won shell. Tandai Menang already mints a draft contract with
     * the same copied fields and NO schedule (QuotationService::markWon) —
     * production's CTR/2026/VIII/0005, a draft 13 days after QTN/2026/VII/0004
     * won, is that shell. A second CTR number for the same quotation would
     * break Quotation::contract() (hasOne) and leave the minted one orphaned,
     * so an untouched shell is COMPLETED in place — same code, filled in — and
     * only a contract that already has a schedule, or has left draft, refuses
     * by name: that one is a signed document, and its value moves through
     * pekerjaan tambah-kurang, not through a second creation.
     */
    public function createFromQuotation(Quotation $quotation, array $data): Contract
    {
        if (! $quotation->isWon()) {
            throw new LogicException(
                "Penawaran {$quotation->code} belum ditandai menang ({$quotation->status->label()});"
                .' kontrak dibuat dari penawaran yang menang — tandai menang dulu.'
            );
        }

        $existing = $quotation->contract()->first();

        if ($existing !== null && ! $this->isUnfilledShell($existing)) {
            throw new LogicException(
                "Penawaran {$quotation->code} sudah memiliki kontrak {$existing->code} ({$existing->status->label()});"
                .' buka kontrak itu — nilai yang berubah setelah kontrak berjalan dicatat lewat pekerjaan tambah-kurang.'
            );
        }

        $payload = array_merge(Arr::except($data, ['customer_id', 'quotation_id']), [
            'customer_id' => $quotation->customer_id,
            'quotation_id' => $quotation->id,
            'title' => $data['title'] ?? $quotation->title,
            'scope_type' => $data['scope_type'] ?? $quotation->scope_type,
            'ppn_rate' => $data['ppn_rate'] ?? $quotation->ppn_rate,
            'value' => $data['value'] ?? $quotation->dpp,
        ]);

        return $existing === null
            ? $this->create($payload)
            : $this->update($existing, $payload);
    }

    /**
     * The contract Tandai Menang leaves behind: a draft with no termin at all.
     * One definition, read by createFromQuotation (complete it) and by
     * QuotationResource (offer "Lengkapi kontrak" for it).
     */
    public function isUnfilledShell(Contract $contract): bool
    {
        return $contract->status === DocumentStatus::Draft && ! $contract->termins()->exists();
    }

    /**
     * Activate the signed contract: schedule must exist and cover exactly 100%.
     */
    public function activate(Contract $contract): Contract
    {
        if (! in_array($contract->status, [DocumentStatus::Draft, DocumentStatus::Submitted], true)) {
            throw new LogicException("Contract {$contract->code} is {$contract->status->value} and cannot be activated.");
        }

        $termins = $contract->termins()->get();

        if ($termins->isEmpty()) {
            throw new LogicException("Contract {$contract->code} has no termin schedule.");
        }

        $sum = round((float) $termins->sum('percent'), 4);

        if (abs($sum - 100.0) > 0.01) {
            throw new LogicException("Contract {$contract->code} termin percents sum to {$sum}, expected 100.");
        }

        $contract->forceFill(['status' => DocumentStatus::Approved])->save();

        return $contract;
    }

    public function delete(Contract $contract): void
    {
        $this->assertEditable($contract);

        // Same guard as QuotationService::delete — the FK's restrictOnDelete
        // only stops HARD deletes, and this delete is soft. The register's
        // motivating case is the ~Rp 9,7 miliar advance-payment bond of
        // CTR/2026/I/0001: its contract must never disappear from under it.
        $guarantee = Guarantee::query()
            ->where('contract_id', $contract->id)
            ->where('status', GuaranteeStatus::Active)
            ->first();

        if ($guarantee !== null) {
            throw new LogicException(
                "Kontrak {$contract->code} masih memiliki jaminan aktif"
                ." ({$guarantee->number} — {$guarantee->issuer});"
                .' tandai jaminan itu dikembalikan/dicairkan atau pindahkan dulu tautannya.'
            );
        }

        $contract->delete();
    }

    /**
     * Termin percents must cover the contract value exactly (sum == 100).
     * InvalidArgumentException extends LogicException, so controllers catch both alike.
     */
    public function assertTerminPercentsSumTo100(array $termins): void
    {
        if ($termins === []) {
            throw new InvalidArgumentException('A contract needs at least one termin.');
        }

        $sum = round(array_sum(array_map(
            static fn (array $termin): float => (float) ($termin['percent'] ?? 0),
            $termins,
        )), 4);

        if (abs($sum - 100.0) > 0.01) {
            throw new InvalidArgumentException("Termin percents must sum to 100, got {$sum}.");
        }
    }

    /**
     * A contract linked to a quotation is worth the quotation's DPP unless
     * somebody says why not (T3.6, ANALISIS-PROSES A1).
     *
     * Measured 4 Sep 2026 on production: QTN/2026/VIII/0008 Rp 2,04 M and
     * CTR/2026/VIII/0004 Rp 1,84 M, "dua angka untuk satu kesepakatan, tanpa
     * jejak mengapa berbeda". The comparison is DPP to DPP (crm_contracts.value
     * excludes PPN, crm_quotations.dpp is the offer before PPN) — the rate is
     * copied along, so the totals agree whenever these do. The refusal names
     * BOTH amounts and the difference: the person at the form has to decide
     * whether the number or the link is wrong, and "wajib diisi" tells them
     * neither.
     *
     * Honesty contract, mirroring pr_bypass_reason (T3.8): the reason is kept
     * only while it explains a difference — same value, or no quotation to
     * differ from, stores NULL whatever was typed — so a stored reason is
     * always attached to a real gap and NULL never means "nobody asked".
     */
    private function applyValueChangeReason(Contract $contract, ?string $reason): void
    {
        $quotation = $contract->quotation_id === null
            ? null
            : Quotation::query()->find($contract->quotation_id);

        $difference = $quotation === null
            ? 0.0
            : round((float) $contract->value - (float) $quotation->dpp, 2);

        if ($quotation === null || abs($difference) < 0.005) {
            $contract->value_change_reason = null;

            return;
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'value_change_reason' => [sprintf(
                    'Nilai kontrak Rp %s berbeda dari nilai penawaran %s Rp %s (DPP, sebelum PPN; selisih Rp %s); isi alasan perubahan nilai.',
                    number_format((float) $contract->value, 2, ',', '.'),
                    $quotation->code,
                    number_format((float) $quotation->dpp, 2, ',', '.'),
                    number_format($difference, 2, ',', '.'),
                )],
            ]);
        }

        $contract->value_change_reason = $reason;
    }

    private function applyTaxTotals(Contract $contract): void
    {
        $value = round((float) $contract->value, 2); // DPP, excl. PPN
        $ppnRate = (float) $contract->ppn_rate;
        $ppnAmount = round($value * $ppnRate / 100, 2);

        $contract->forceFill([
            'value' => $value,
            'ppn_amount' => $ppnAmount,
            'total_with_ppn' => round($value + $ppnAmount, 2),
        ]);
    }

    private function syncTermins(Contract $contract, array $termins): void
    {
        $contract->termins()->delete();

        $value = (float) $contract->value;
        $count = count($termins);
        $allocated = 0.0;
        $no = 0;

        foreach (array_values($termins) as $index => $termin) {
            $no++;
            $percent = round((float) ($termin['percent'] ?? 0), 4);

            // Last termin absorbs the rounding residue so amounts sum exactly to the value.
            $amount = $index === $count - 1
                ? round($value - $allocated, 2)
                : round($value * $percent / 100, 2);
            $allocated = round($allocated + $amount, 2);

            $contract->termins()->create([
                'termin_no' => $no,
                'name' => $termin['name'],
                'percent' => $percent,
                'amount' => $amount,
                'billing_condition' => $termin['billing_condition'] ?? null,
                // Menandai pola "retensi sebagai termin" — begitu satu termin
                // ber-flag, potongan retensi per invoice ditolak untuk kontrak
                // ini (temuan #73, lihat Contract::hasRetentionTermin()).
                'is_retention' => (bool) ($termin['is_retention'] ?? false),
                'due_date' => $termin['due_date'] ?? null,
                'billed_at' => $termin['billed_at'] ?? null,
            ]);
        }
    }

    private function respreadTerminAmounts(Contract $contract): void
    {
        $termins = $contract->termins()->get();

        if ($termins->isEmpty()) {
            return;
        }

        $value = (float) $contract->value;
        $allocated = 0.0;
        $lastId = $termins->last()->id;

        foreach ($termins as $termin) {
            $amount = $termin->id === $lastId
                ? round($value - $allocated, 2)
                : round($value * (float) $termin->percent / 100, 2);
            $allocated = round($allocated + $amount, 2);

            $termin->update(['amount' => $amount]);
        }
    }

    private function assertEditable(Contract $contract): void
    {
        if (! $contract->status->isEditable()) {
            throw new LogicException("Contract {$contract->code} is {$contract->status->value} and can no longer be edited.");
        }
    }
}
