<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kasbon joins the replenishment review set (temuan paket 10 #7).
 *
 * A settled kasbon spends drawer cash through fin_kasbon_lines, never through
 * a voucher — the demo shape: advance Rp 1.000.000, receipts Rp 800.000,
 * change Rp 200.000 back. Without a stamp of its own, that Rp 800.000 was in
 * the replenishment's bank amount (the imprest rule prices float − balance)
 * but in NOBODY's review set: the approver saw Rp 200.000 of bons backing a
 * Rp 1.000.000 transfer, and the cashier screen's imprest identity
 * (float − bon − kasbon) reported a permanent false drift of Rp 800.000.
 *
 * Same lifecycle as fin_petty_cash_vouchers.replenishment_payment_id: stamped
 * at replenishment SUBMIT (the frozen set the approver reads), unstamped on
 * reject, and counted as "belum diganti" until the stamping payment POSTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_kasbons', 'replenishment_payment_id')) {
            Schema::table('fin_kasbons', function (Blueprint $table): void {
                $table->foreignId('replenishment_payment_id')
                    ->nullable()
                    ->constrained('fin_payments');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fin_kasbons', 'replenishment_payment_id')) {
            Schema::table('fin_kasbons', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('replenishment_payment_id');
            });
        }
    }
};
