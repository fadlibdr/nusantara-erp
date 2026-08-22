<?php

namespace Modules\Finance\Support;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\PaymentStatus;

/**
 * Sisa dokumen AP/AR PER TANGGAL — the one basis an open-item report may use.
 *
 * fin_ar_invoices.amount_paid and fin_ap_bills.amount_paid are LIFETIME
 * figures: PaymentService::settleInvoice adds the allocation the moment the
 * payment posts, whatever date that payment carries. Nothing bounds
 * payment_date — PaymentStoreRequest is `['required', 'date']` and
 * assertPeriodOpen only asks that the period exist and be open, and every 2026
 * period on the demo is open — so a post-dated giro keyed today removes the
 * receivable from every amount_paid-based report weeks before it leaves the
 * ledger.
 *
 * PeriodCloseService::subledgerOutstanding already refused amount_paid for
 * exactly this reason ("a lifetime figure a July payment moves after June has
 * closed") and derives the month-end tie-out from allocations instead. It
 * compares that derivation against GL 1-1300 / 2-1100 as a close checklist
 * item, so any report that keeps using amount_paid is guaranteed to disagree
 * with the number the closer is asked to sign. The measured disagreement on
 * the demo dataset: a receipt of Rp 300.000.000 dated 2026-09-15 and posted on
 * 2026-08-03 dropped the AR aging from Rp 560.000.000 to Rp 260.000.000 while
 * GL 1-1300 on the same day still read Rp 560.000.000.
 *
 * This is the per-document form of that same query, shared by
 * ReportService::agingReport and CashFlowService::projection so the three
 * surfaces cannot drift apart again.
 */
final class OutstandingAsOf
{
    /**
     * How much of each document was settled by payments POSTED and dated on or
     * before $asOf.
     *
     * Allocations mirror amount_paid exactly — settleInvoice()/settleBill()
     * write the same figure to both, gross of withholding — so the only
     * difference between this and amount_paid is the date bound, which is the
     * whole point.
     *
     * @param  string  $payableType  a PaymentAllocation::TYPE_* constant
     * @param  array<int, int>  $documentIds
     * @return array<int, float> settled amount keyed by document id
     */
    public static function settled(string $payableType, array $documentIds, string $asOf): array
    {
        if ($documentIds === []) {
            return [];
        }

        $rows = DB::table('fin_payment_allocations')
            ->join('fin_payments', 'fin_payments.id', '=', 'fin_payment_allocations.payment_id')
            ->where('fin_payment_allocations.payable_type', $payableType)
            ->whereIn('fin_payment_allocations.payable_id', $documentIds)
            ->where('fin_payments.status', PaymentStatus::Posted->value)
            ->whereNull('fin_payments.deleted_at')
            // whereDate, same reason as PeriodCloseService::controlBalance:
            // payment_date is cast `date` and stored "…-09-15 00:00:00", which
            // a raw string <= comparison drops on the boundary day itself.
            ->whereDate('fin_payments.payment_date', '<=', $asOf)
            ->groupBy('fin_payment_allocations.payable_id')
            ->selectRaw('fin_payment_allocations.payable_id as document_id, SUM(fin_payment_allocations.amount) as settled')
            ->get();

        $settled = [];

        foreach ($rows as $row) {
            $settled[(int) $row->document_id] = round((float) $row->settled, 2);
        }

        return $settled;
    }
}
