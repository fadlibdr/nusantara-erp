<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three things an AP bill could not say before.
 *
 * is_advance             This bill is a down payment (uang muka) against a PO,
 *                        not the invoice for the goods. It skips the
 *                        goods-received gate, debits the purchase advance asset
 *                        account instead of an expense, records no project cost,
 *                        and is netted off when the final bill is approved.
 *                        Without it the standard Indonesian 20–30 % DP against a
 *                        material PO could not be recorded or paid at all: the
 *                        gate refused approval before a receipt existed and
 *                        PaymentService only settles approved bills.
 *
 * goods_receipt_id       This bill settles a specific goods receipt that has no
 *                        purchase order. That is the debit path the receipt
 *                        accrual account never had: the receipt credits it, this
 *                        bill debits it back. Without a document that can clear
 *                        it, the accrual was a liability only a hand-written
 *                        journal voucher could ever remove.
 *
 * gl_cleared_amount      What this bill actually debited out of the receipts'
 * advance_applied_amount recorded clearing accounts, and how much approved
 *                        advance it consumed. Both are written at approval from
 *                        what was posted, so "how much of this PO's GR/IR is
 *                        still outstanding" and "how much prepayment is left"
 *                        are answered from the ledger's own record rather than
 *                        re-derived from today's configuration.
 *
 * gl_cleared_amount is back-filled for bills approved before this migration by
 * reading their posted journal, so an existing installation cannot clear the
 * same receipt twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_ap_bills')) {
            return;
        }

        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            if (! Schema::hasColumn('fin_ap_bills', 'is_advance')) {
                $table->boolean('is_advance')->default(false)->after('subcontract_claim_id');
            }

            // Cross-module reference (inv_goods_receipts) — indexed, no DB constraint.
            if (! Schema::hasColumn('fin_ap_bills', 'goods_receipt_id')) {
                $table->unsignedBigInteger('goods_receipt_id')->nullable()->after('purchase_order_id');
                $table->index('goods_receipt_id');
            }

            if (! Schema::hasColumn('fin_ap_bills', 'gl_cleared_amount')) {
                $table->decimal('gl_cleared_amount', 18, 2)->default(0)->after('total_payable');
            }

            if (! Schema::hasColumn('fin_ap_bills', 'advance_applied_amount')) {
                $table->decimal('advance_applied_amount', 18, 2)->default(0)->after('gl_cleared_amount');
            }
        });

        $this->backfillClearedAmounts();
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_ap_bills')) {
            return;
        }

        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            foreach (['is_advance', 'goods_receipt_id', 'gl_cleared_amount', 'advance_applied_amount'] as $column) {
                if (Schema::hasColumn('fin_ap_bills', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * A bill approved under the previous build already debited GR/IR without
     * recording how much. Read it back off its posted journal — the credit side
     * of the receipts is what the new outstanding calculation subtracts from, so
     * leaving these at zero would let a second bill clear the same value again.
     *
     * "How much did this bill debit to a clearing account" is answered by the
     * ledger: any debit to an account a goods receipt recorded as its clearing
     * account. Reading the ledger keeps the back-fill exact even where the
     * account codes were overridden.
     */
    private function backfillClearedAmounts(): void
    {
        if (! Schema::hasTable('fin_journals')
            || ! Schema::hasTable('fin_journal_lines')
            || ! Schema::hasTable('fin_accounts')
            || ! Schema::hasTable('inv_goods_receipts')
            || ! Schema::hasColumn('inv_goods_receipts', 'gl_clearing_account')) {
            return;
        }

        $clearingCodes = DB::table('inv_goods_receipts')
            ->whereNotNull('gl_clearing_account')
            ->distinct()
            ->pluck('gl_clearing_account')
            ->all();

        if ($clearingCodes === []) {
            return;
        }

        $rows = DB::table('fin_ap_bills')
            ->where('gl_cleared_amount', 0)
            ->whereNotNull('purchase_order_id')
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($rows as $billId) {
            $debit = DB::table('fin_journal_lines')
                ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
                ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
                ->where('fin_journals.reference_type', 'ap_bill')
                ->where('fin_journals.reference_id', $billId)
                ->where('fin_journals.status', 'posted')
                ->whereNull('fin_journals.deleted_at')
                ->whereIn('fin_accounts.code', $clearingCodes)
                ->sum('fin_journal_lines.debit');

            if (round((float) $debit, 2) > 0.0) {
                DB::table('fin_ap_bills')
                    ->where('id', $billId)
                    ->update(['gl_cleared_amount' => round((float) $debit, 2)]);
            }
        }
    }
};
