<?php

namespace Modules\Finance\Services;

use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Finance\Models\ArInvoice;

/**
 * UANG MUKA PEMILIK (P3) — the owner-side twin of
 * Subcontract\Services\AdvanceService, rupiah for rupiah and rule for rule.
 *
 * WHY THERE IS ANYTHING TO RECOVER AT ALL. Under the termin-percentage model
 * the repo shipped with, a "DP 20 %" termin plus progress termins of 80 % sum
 * to the contract: nothing is advanced and nothing is recovered. An OPNAME-BASED
 * claim is a different animal — it bills the FULL value of the work measured,
 * so across the job it bills 100 %, and the DP paid before any of it was built
 * has to come back out proportionally. That is the same shape an SPK has, and
 * the arithmetic below is deliberately identical:
 *
 *   recovery = dpp x (DP billed ÷ contract value)
 *
 * with the same two corrections AdvanceService documents:
 *
 *   catch-up   the claim that brings cumulative billed work to the contract
 *              value takes the whole remainder, so per-claim rounding cannot
 *              strand the last few rupiah of the DP;
 *   floor      never more than dpp − retensi − denda, so an invoice total
 *              cannot go negative. What the floor leaves unrecovered stays in
 *              `outstanding` and rolls to the next claim.
 *
 * "THE DP ACTUALLY BILLED" means an APPROVED, non-cancelled invoice flagged
 * is_advance. A draft DP is a proposal; a cancelled one had its journal
 * reversed. Neither is money the customer has been asked for, and recovering
 * against either would short-pay ourselves on our own claim.
 */
class OwnerAdvanceService
{
    /** The DP billed on this contract — approved advance invoices only. */
    public function funded(Contract $contract): float
    {
        return round((float) ArInvoice::query()
            ->where('contract_id', $contract->id)
            ->where('is_advance', true)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->sum('dpp'), 2);
    }

    /** Of that DP, how much progress claims have already recovered. */
    public function recovered(Contract $contract, ?int $excludeInvoiceId = null): float
    {
        return round((float) ArInvoice::query()
            ->where('contract_id', $contract->id)
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->when($excludeInvoiceId !== null, fn ($query) => $query->whereKeyNot($excludeInvoiceId))
            ->sum('advance_recovery_amount'), 2);
    }

    /** Never negative: a legacy over-recovery must not invite recovering more. */
    public function outstanding(Contract $contract, ?int $excludeInvoiceId = null): float
    {
        return max(round($this->funded($contract) - $this->recovered($contract, $excludeInvoiceId), 2), 0.0);
    }

    /**
     * The recovery an owner claim of $dpp should carry. Zero — always — when no
     * DP was ever billed on this contract.
     */
    public function recoveryFor(
        Contract $contract,
        ?int $invoiceId,
        float $dpp,
        float $retention,
        float $penalty,
    ): float {
        $funded = $this->funded($contract);

        if ($funded <= 0.0) {
            return 0.0;
        }

        $outstanding = $this->outstanding($contract, $invoiceId);
        $value = round((float) $contract->value, 2);

        if ($outstanding <= 0.0 || $value <= 0.0) {
            return 0.0;
        }

        $recovery = round($dpp * $funded / $value, 2);

        $othersDpp = round((float) ArInvoice::query()
            ->where('contract_id', $contract->id)
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->when($invoiceId !== null, fn ($query) => $query->whereKeyNot($invoiceId))
            ->sum('dpp'), 2);

        if ($othersDpp + $dpp >= $value - 0.01) {
            $recovery = $outstanding; // catch-up: this claim closes the contract out
        }

        return max(min($recovery, $outstanding, round($dpp - $retention - $penalty, 2)), 0.0);
    }

    /** Everything the owner-claim screen needs to tell the DP's story. */
    public function panelFor(Contract $contract): array
    {
        return [
            'contract_id' => $contract->id,
            'contract_value' => round((float) $contract->value, 2),
            'funded' => $this->funded($contract),
            'recovered' => $this->recovered($contract),
            'outstanding' => $this->outstanding($contract),
        ];
    }
}
