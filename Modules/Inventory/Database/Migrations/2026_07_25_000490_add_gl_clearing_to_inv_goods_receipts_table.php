<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The goods receipt records what its GL posting actually did.
 *
 * Before this, "which liability did the receipt credit, and for how much" was
 * re-derived at billing time from the PURCHASE ORDER's deliver-to warehouse and
 * from the CURRENT value of accounting.perpetual_inventory. Those two inputs are
 * not the ones the receipt used, so the two ends of the GR/IR chain could — and
 * did — disagree: a PO with no warehouse credited the clearing account and was
 * never cleared, and toggling the perpetual switch between receipt and invoice
 * either stranded a credit or debited one that had never been raised.
 *
 * Persisting the decision removes the second derivation entirely. The bill can
 * only ever clear what a receipt wrote here, so both failures become impossible
 * by construction rather than compensated for afterwards.
 *
 *   gl_clearing_account  COA code the receipt journal actually credited
 *   gl_clearing_amount   rupiah it credited to that account
 *
 * Both stay NULL when there is nothing for a vendor bill to clear: periodic
 * inventory (no journal at all), a zero-value receipt, or a receipt with neither
 * a PO nor a vendor — opening/found stock, whose credit goes to the stock
 * variance account and is closed at source, exactly like an opname surplus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inv_goods_receipts')) {
            return;
        }

        Schema::table('inv_goods_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('inv_goods_receipts', 'gl_clearing_account')) {
                $table->string('gl_clearing_account', 20)->nullable()->after('status');
            }

            if (! Schema::hasColumn('inv_goods_receipts', 'gl_clearing_amount')) {
                $table->decimal('gl_clearing_amount', 18, 2)->nullable()->after('gl_clearing_account');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inv_goods_receipts')) {
            return;
        }

        Schema::table('inv_goods_receipts', function (Blueprint $table): void {
            foreach (['gl_clearing_account', 'gl_clearing_amount'] as $column) {
                if (Schema::hasColumn('inv_goods_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
