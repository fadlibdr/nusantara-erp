<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda termin retensi — temuan #73, dua pola retensi tanpa pagar.
 *
 * The system supports two legitimate retention patterns and, until this
 * column, could not tell which one a contract uses:
 *
 *  (a) withheld PER INVOICE — the "Tahan retensi sesuai kontrak" checkbox,
 *      dpp x retention_pct, booked to 1-1350 Piutang Retensi on approval;
 *  (b) retention AS A TERMIN — a "Retensi 5%" line inside a schedule that
 *      sums to 100% (the pattern of live contracts 1 and 2), collected by
 *      billing that termin like any other.
 *
 * Nothing stopped finance from ticking (a) on termins 1-4 AND then billing
 * the "Retensi 5%" termin: 1-1350 doubles (~5% of contract value, Rp 2,4 M
 * on contract 1), the customer is effectively billed 105%, and the
 * retention-vs-contract reconciliation can never come out.
 *
 * The flag names pattern (b) explicitly so ArInvoiceService can refuse
 * pattern (a) on such a contract. NOT backfilled, on purpose: guessing which
 * historical "Retensi …" termins meant pattern (b) would invent a fact on
 * schedules that were already billed — existing rows keep false and the
 * guard only arms on schedules flagged after this column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contract_termins', function (Blueprint $table): void {
            $table->boolean('is_retention')->default(false)->after('billing_condition');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contract_termins', function (Blueprint $table): void {
            $table->dropColumn('is_retention');
        });
    }
};
