<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text reference on an allocation row, for the non-AP settlement kind
 * (payable_type = 'gl_account'): "SSP PPh 21 masa Juni 2026, NTPN 0123…" is the
 * one sentence that ties a tax payment to its state receipt, and it belongs on
 * the row that names the liability being discharged.
 *
 * WHY A COLUMN AND NOT A TABLE — the mirror of the fin_payment_withholdings
 * decision. A withholding is a DIFFERENT FACT about a settlement (no cash
 * moved, its own statutory certificate, its own journal leg), so it earned its
 * own table. A remark is not a fact at all: it is a memo ABOUT the allocation
 * row, moves no money, books no journal of its own, and is deliberately
 * excluded from the allocation signature the approval covers — see
 * PaymentService::allocationSignature(). One nullable column, null on every
 * ar_invoice/ap_bill row, is exactly as heavy as that is.
 *
 * Numbering: Finance's 001100–001199 block is exhausted; continuing
 * date-forward per 2026_07_26_001100's note. 2026_08_01_001106 is taken by
 * add_created_by_to_fin_journals; this is the next free slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_payment_allocations', function (Blueprint $table): void {
            $table->string('remark', 150)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fin_payment_allocations', function (Blueprint $table): void {
            $table->dropColumn('remark');
        });
    }
};
