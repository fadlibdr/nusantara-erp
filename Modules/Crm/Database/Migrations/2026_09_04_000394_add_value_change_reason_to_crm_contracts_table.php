<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3.6 — why a contract is worth something else than the quotation it came from.
 *
 * Production, 4 Sep 2026 (ANALISIS-PROSES §1.1, gap A1): QTN/2026/VIII/0008
 * Rp 2,04 M became CTR/2026/VIII/0004 Rp 1,84 M — two numbers for one deal,
 * typed by hand, no quotation_id, and nowhere to write down the Rp 200 jt that
 * went missing between them. crm_contracts.quotation_id has existed since
 * migration 000340 (optional, and the contract form never asked for the
 * difference); this column is the missing half. ContractService refuses a
 * linked contract whose value differs from the quotation's DPP until this says
 * why, and clears it again when the two agree — so NULL always means "same
 * value as the offer" (or no offer to compare with), never "nobody asked".
 * string(500), the size of every other reason column on a document
 * (lost_reason, qualification_override_reason, pr_bypass_reason).
 *
 * hasColumn guard + rollback: the 000870 (pr_bypass_reason) pattern.
 * FORWARD-ONLY — no backfill: the three seeded contracts equal their
 * quotations' DPP to the rupiah (CrmDatabaseSeeder), and a contract typed
 * without a link has nothing to be compared against.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('crm_contracts', 'value_change_reason')) {
            return;
        }

        Schema::table('crm_contracts', function (Blueprint $table): void {
            $table->string('value_change_reason', 500)->nullable()->after('total_with_ppn');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('crm_contracts', 'value_change_reason')) {
            return;
        }

        Schema::table('crm_contracts', function (Blueprint $table): void {
            $table->dropColumn('value_change_reason');
        });
    }
};
