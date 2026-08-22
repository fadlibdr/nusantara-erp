<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan parsial (#40): which posted goods receipts one AP bill covers.
 *
 * Before this table a purchase order had exactly one non-advance bill
 * (finalBillExists refused a second), so a PO delivered in three shipments
 * across three months could only be invoiced once, at the end — while the
 * vendor invoices each delivery note as it happens. The bill-per-(PO, GRN set)
 * shape needs a record of WHICH receipts a bill prices and clears, and that
 * record is this table.
 *
 * One row per (bill, receipt), and the columns are recorded FACTS in the
 * gl_cleared_amount tradition, not re-derivations:
 *
 *   dpp_amount      the slice of the bill's gross DPP this receipt contributes
 *                   (received qty x PO unit price, less its progressive share
 *                   of the PO header discount), written at create;
 *   cleared_amount  what approving the bill actually debited out of THIS
 *                   receipt's recorded clearing credit, written at approval.
 *                   Per receipt, not per bill, because a partial bill's sweep
 *                   must never be pooled oldest-first across the PO the way a
 *                   whole-PO bill's is — pooling is exactly how a slice cleared
 *                   for GRN B would be subtracted from GRN A's outstanding.
 *
 * THE UNIQUE INDEX ON goods_receipt_id IS THE DOUBLE-BILLING LOCK. Two clerks
 * naming the same receipt on two bills in the same second both pass the
 * polite existence check; the second INSERT then dies here, inside its
 * transaction, and the whole bill rolls back. Rows are deleted when their bill
 * is cancelled or a draft is deleted — releasing the receipt for a corrected
 * bill is the point of cancelling — so the index only ever holds live claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_ap_bill_goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ap_bill_id')->constrained('fin_ap_bills');
            // Cross-module reference (inv_goods_receipts) — no DB constraint;
            // the unique index below doubles as its index.
            $table->unsignedBigInteger('goods_receipt_id');
            $table->decimal('dpp_amount', 18, 2);
            $table->decimal('cleared_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->unique('goods_receipt_id');
            $table->index('ap_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_ap_bill_goods_receipts');
    }
};
