<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 — fin_ap_bills.labor_claim_id: tagihan atas opname mandor
 * (scm_labor_claims), cermin persis subcontract_claim_id.
 *
 * KOLOM BARU, BUKAN PEMAKAIAN ULANG subcontract_claim_id. Alasannya ditulis
 * di sini karena godaannya nyata: satu kolom yang menunjuk dua tabel
 * (scm_progress_claims ATAU scm_labor_claims) tanpa kolom diskriminator
 * adalah angka yang tak bisa diaudit — setiap pembaca subcontract_claim_id
 * yang ada hari ini (subconAdvanceRecovery, assertRetentionNotReleased,
 * assertAdvanceNotConsumed, TaxExport, layar SPA) men-join ke
 * scm_progress_claims dan akan diam-diam membaca opname subkon orang lain
 * ketika id-nya kebetulan sama. Lintas-modul tanpa FK sesuai CONVENTIONS §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('labor_claim_id')->nullable()->after('subcontract_claim_id');
            $table->index('labor_claim_id');
        });
    }

    public function down(): void
    {
        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->dropIndex(['labor_claim_id']);
            $table->dropColumn('labor_claim_id');
        });
    }
};
