<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur pembelian ke vendor (temuan 38): rejected or surplus goods go back on
 * the truck they came off.
 *
 * Before this document the only exit was an opname: stock out at 6-4400
 * Selisih Persediaan — an operating EXPENSE for goods the company is not even
 * keeping — while the vendor's bill stayed billable in full, because nothing
 * reduced the receipt's recorded clearing and nothing ever subtracted from
 * prc_purchase_order_items.qty_received (registerReceipt only adds, and an
 * auto-closed PO stayed closed against the replacement delivery).
 *
 * Each line references the RECEIPT LINE it reverses: that line's unit_cost is
 * the price the goods arrived at, so the slice debited back out of the
 * clearing liability is the slice the receipt once credited — the same
 * "the record on the receipt, not a re-derivation" rule ApBillService clears
 * bills by. Posting also decrements the receipt's gl_clearing_amount, which is
 * the single figure a vendor bill may sweep, so the returned slice provably
 * can never be billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // RPB/{Y}/{RM}/{N4}
            $table->foreignId('goods_receipt_id')->constrained('inv_goods_receipts');
            // Copied from the GRN at creation: the goods leave the warehouse
            // that received them.
            $table->foreignId('warehouse_id')->constrained('inv_warehouses');
            // Who takes the goods back — the GRN's vendor, or its PO's. Kept on
            // the return so the document still names a counterparty after the
            // receipt's PO is soft-deleted.
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->date('return_date');
            $table->unsignedBigInteger('returned_by')->nullable(); // users.id
            $table->string('reason', 500);
            $table->string('status', 30)->default('draft'); // draft / posted
            $table->timestamps();
            $table->softDeletes();

            $table->index('goods_receipt_id');
            $table->index('vendor_id');
            $table->index('status');
        });

        Schema::create('inv_purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('inv_purchase_returns');
            $table->foreignId('grn_item_id')->constrained('inv_goods_receipt_items');
            $table->foreignId('item_id')->constrained('inv_items');
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_cost', 18, 2)->default(0); // frozen from the receipt line at posting
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();

            $table->index('grn_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_purchase_return_items');
        Schema::dropIfExists('inv_purchase_returns');
    }
};
