<?php

namespace Modules\Crm\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Enums\LeadStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;

class QuotationService
{
    public function create(array $data): Quotation
    {
        return DB::transaction(function () use ($data): Quotation {
            $items = Arr::pull($data, 'items', []);

            $data['ppn_rate'] = $data['ppn_rate'] ?? Erp::float('tax.ppn_rate', 11.0);

            $quotation = new Quotation(Arr::except($data, ['code', 'status']));
            $quotation->status = DocumentStatus::Draft;
            $quotation->save(); // HasDocumentNumber fills the QTN code

            $this->syncItems($quotation, $items);
            $this->recomputeTotals($quotation);

            return $quotation->load('items', 'customer');
        });
    }

    public function update(Quotation $quotation, array $data): Quotation
    {
        $this->assertEditable($quotation);

        return DB::transaction(function () use ($quotation, $data): Quotation {
            $items = Arr::pull($data, 'items');

            $quotation->fill(Arr::except($data, ['code', 'status', 'revision', 'won_at', 'lost_at']));
            $quotation->save();

            if (is_array($items)) {
                $this->syncItems($quotation, $items); // lines are replaced wholesale
            }

            $this->recomputeTotals($quotation);

            return $quotation->load('items', 'customer');
        });
    }

    /**
     * subtotal = sum(line amounts); dpp = subtotal - discount;
     * ppn = dpp * rate / 100; total = dpp + ppn.
     */
    public function recomputeTotals(Quotation $quotation): Quotation
    {
        $subtotal = round((float) $quotation->items()->sum('amount'), 2);
        $discount = round(min((float) $quotation->discount_amount, $subtotal), 2);
        $dpp = round($subtotal - $discount, 2);
        $ppnRate = (float) ($quotation->ppn_rate ?? Erp::float('tax.ppn_rate', 11.0));
        $ppnAmount = round($dpp * $ppnRate / 100, 2);

        $quotation->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'dpp' => $dpp,
            'ppn_rate' => $ppnRate,
            'ppn_amount' => $ppnAmount,
            'total' => round($dpp + $ppnAmount, 2),
        ])->save();

        return $quotation;
    }

    /**
     * Approved quotation won by the customer: stamp won_at and open a draft
     * contract carrying over the commercial data (value = DPP, excl. PPN).
     */
    public function markWon(Quotation $quotation): Contract
    {
        if ($quotation->status !== DocumentStatus::Approved) {
            throw new LogicException("Only an approved quotation can be marked won ({$quotation->code} is {$quotation->status->value}).");
        }

        if ($quotation->isWon() || $quotation->isLost()) {
            throw new LogicException("Quotation {$quotation->code} outcome has already been decided.");
        }

        return DB::transaction(function () use ($quotation): Contract {
            $quotation->forceFill(['won_at' => now()])->save();

            // Temuan #58: lead status was purely manual, so the pipeline froze
            // at "Penawaran Dikirim" unless somebody remembered to edit it —
            // win-rate per sales was never right. The quotation's fate now
            // drags its lead along.
            $quotation->lead?->forceFill(['status' => LeadStatus::Won])->save();

            $contract = new Contract([
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'title' => $quotation->title,
                'scope_type' => $quotation->scope_type,
                'value' => $quotation->dpp,
                'ppn_rate' => $quotation->ppn_rate,
                'ppn_amount' => $quotation->ppn_amount,
                'total_with_ppn' => $quotation->total,
                'retention_pct' => Erp::float('projects.default_retention_pct', 5.0),
                'status' => DocumentStatus::Draft,
            ]);
            $contract->save(); // HasDocumentNumber fills the CTR code

            return $contract;
        });
    }

    public function markLost(Quotation $quotation, ?string $reason = null): Quotation
    {
        if ($quotation->isWon() || $quotation->isLost()) {
            throw new LogicException("Quotation {$quotation->code} outcome has already been decided.");
        }

        return DB::transaction(function () use ($quotation, $reason): Quotation {
            $quotation->forceFill([
                'lost_at' => now(),
                'lost_reason' => $reason,
                'status' => DocumentStatus::Closed,
            ])->save();

            // Losing drags the lead down too (temuan #58) — UNLESS the lead
            // already won through another quotation: a second package lost
            // must not demote a lead whose customer relationship exists.
            $lead = $quotation->lead;

            if ($lead !== null && $lead->status !== LeadStatus::Won) {
                $lead->forceFill(['status' => LeadStatus::Lost])->save();
            }

            return $quotation;
        });
    }

    /**
     * New revision: bump the counter and reopen as draft so lines can be edited.
     * A won quotation is frozen — the contract carries the change orders.
     */
    public function revise(Quotation $quotation): Quotation
    {
        if ($quotation->isWon()) {
            throw new LogicException("Quotation {$quotation->code} has been won; revise via the contract instead.");
        }

        $quotation->forceFill([
            'revision' => $quotation->revision + 1,
            'status' => DocumentStatus::Draft,
            'lost_at' => null,
            'lost_reason' => null,
        ])->save();

        return $quotation;
    }

    public function delete(Quotation $quotation): void
    {
        $this->assertEditable($quotation);

        // The crm_guarantees FKs restrictOnDelete, but a soft delete is an
        // UPDATE — the constraint never fires. Without this check a draft
        // quotation carrying a live bid bond (bid bonds legitimately attach
        // pre-contract) could vanish, leaving an active guarantee the 30-day
        // watcher keeps escalating while no screen can reach it.
        $guarantee = Guarantee::query()
            ->where('quotation_id', $quotation->id)
            ->where('status', GuaranteeStatus::Active)
            ->first();

        if ($guarantee !== null) {
            throw new LogicException(
                "Penawaran {$quotation->code} masih memiliki jaminan aktif"
                ." ({$guarantee->number} — {$guarantee->issuer});"
                .' tandai jaminan itu dikembalikan/dicairkan atau pindahkan dulu tautannya.'
            );
        }

        $quotation->delete();
    }

    private function syncItems(Quotation $quotation, array $items): void
    {
        $quotation->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $qty = round((float) ($item['qty'] ?? 1), 3);
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

            $quotation->items()->create([
                'line_no' => ++$lineNo,
                'description' => $item['description'],
                'qty' => $qty,
                'unit' => $item['unit'] ?? null,
                'unit_price' => $unitPrice,
                'amount' => round($qty * $unitPrice, 2),
            ]);
        }
    }

    private function assertEditable(Quotation $quotation): void
    {
        if (! $quotation->status->isEditable()) {
            throw new LogicException("Quotation {$quotation->code} is {$quotation->status->value} and can no longer be edited.");
        }
    }
}
