<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Guarantee;

class ContractService
{
    public function create(array $data): Contract
    {
        $termins = Arr::pull($data, 'termins', []);
        $this->assertTerminPercentsSumTo100($termins);

        return DB::transaction(function () use ($data, $termins): Contract {
            $data['ppn_rate'] = $data['ppn_rate'] ?? Erp::float('tax.ppn_rate', 11.0);
            $data['retention_pct'] = $data['retention_pct'] ?? Erp::float('projects.default_retention_pct', 5.0);

            $contract = new Contract(Arr::except($data, ['code', 'status']));
            $contract->status = DocumentStatus::Draft;
            $this->applyTaxTotals($contract);
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

            $contract->fill(Arr::except($data, ['code', 'status']));
            $this->applyTaxTotals($contract);
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
