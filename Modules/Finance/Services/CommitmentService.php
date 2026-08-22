<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;

/**
 * Committed cost: money a project has promised but not yet been billed for.
 *
 * The profitability report compared RAP against ACTUAL cost, and actual cost is
 * written only when a vendor bill is approved. A project manager who had already
 * signed Rp 5 miliar of purchase orders and subcontracts saw none of it — the
 * report said the budget was intact right up until the invoices arrived.
 *
 * A commitment is not a cost and is deliberately NOT posted anywhere. It never
 * touches the ledger and never enters fin_project_costs: nothing has been
 * received, and an accrual for work not yet done would be wrong. It is reported
 * beside actual cost so the remaining budget is the one a decision can be made
 * on:
 *
 *     sisa anggaran = RAP − aktual − komitmen
 *
 * What counts as committed:
 *
 *   PURCHASE ORDERS   approved or closed, minus what has already been billed
 *                     against them. Closed POs are included because closing
 *                     means fully received, not fully invoiced.
 *   SUBCONTRACTS      approved, minus the gross value of approved opname claims.
 *                     The SPK is the promise; each claim converts part of it
 *                     into an actual cost.
 *
 * Draft and submitted documents are excluded. Nothing is promised until somebody
 * with the authority to promise it has approved it.
 */
class CommitmentService
{
    /**
     * @return array{purchase_orders: float, subcontracts: float, total: float, detail: array}
     */
    public function forProject(int $projectId): array
    {
        $orders = $this->openPurchaseOrders($projectId);
        $subcontracts = $this->openSubcontracts($projectId);

        return [
            'purchase_orders' => round($orders['outstanding'], 2),
            'subcontracts' => round($subcontracts['outstanding'], 2),
            'total' => round($orders['outstanding'] + $subcontracts['outstanding'], 2),
            'detail' => [
                'purchase_orders' => $orders,
                'subcontracts' => $subcontracts,
            ],
        ];
    }

    /**
     * Approved and closed POs, less what has been billed against each.
     *
     * Compared on DPP rather than total: the billed side is fin_ap_bills.dpp,
     * and comparing a PPN-inclusive order total against a PPN-exclusive billed
     * amount would report every fully-billed order as still 11% outstanding.
     */
    private function openPurchaseOrders(int $projectId): array
    {
        $rows = DB::table('prc_purchase_orders as po')
            ->leftJoin('fin_ap_bills as b', function ($join): void {
                $join->on('b.purchase_order_id', '=', 'po.id')
                    ->where('b.status', DocumentStatus::Approved->value)
                    ->whereNull('b.deleted_at');
            })
            ->where('po.project_id', $projectId)
            ->whereIn('po.status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->whereNull('po.deleted_at')
            ->groupBy('po.id', 'po.code', 'po.dpp')
            ->selectRaw('po.id, po.code, po.dpp, COALESCE(SUM(b.dpp), 0) as billed')
            ->get();

        return $this->summarise($rows);
    }

    /**
     * Approved SPKs, less the gross value of approved opname claims.
     *
     * Gross, not net payable: retention and PPh are deductions from the payment,
     * not from the work. A claim that withholds 5% retention has still consumed
     * its full share of the subcontract.
     */
    private function openSubcontracts(int $projectId): array
    {
        $rows = DB::table('scm_subcontracts as s')
            ->leftJoin('scm_progress_claims as c', function ($join): void {
                $join->on('c.subcontract_id', '=', 's.id')
                    ->where('c.status', DocumentStatus::Approved->value)
                    ->whereNull('c.deleted_at');
            })
            ->where('s.project_id', $projectId)
            ->where('s.status', DocumentStatus::Approved->value)
            ->whereNull('s.deleted_at')
            ->groupBy('s.id', 's.code', 's.value')
            ->selectRaw('s.id, s.code, s.value as dpp, COALESCE(SUM(c.gross_amount), 0) as billed')
            ->get();

        return $this->summarise($rows);
    }

    private function summarise($rows): array
    {
        $outstanding = 0.0;
        $items = [];

        foreach ($rows as $row) {
            // Never negative: over-billing an order is a real event, and it is a
            // three-way-match problem reported elsewhere. Letting it show here as
            // a negative commitment would quietly offset a genuine commitment on
            // another document and understate the total.
            $remaining = max(0.0, round((float) $row->dpp - (float) $row->billed, 2));

            if ($remaining <= 0) {
                continue;
            }

            $outstanding += $remaining;
            $items[] = [
                'code' => $row->code,
                'value' => round((float) $row->dpp, 2),
                'billed' => round((float) $row->billed, 2),
                'outstanding' => $remaining,
            ];
        }

        return ['outstanding' => $outstanding, 'count' => count($items), 'items' => $items];
    }
}
