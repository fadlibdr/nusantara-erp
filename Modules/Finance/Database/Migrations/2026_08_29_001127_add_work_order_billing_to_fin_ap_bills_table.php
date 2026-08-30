<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 — fin_ap_bills.work_order_billing_id: tagihan atas satu periode PPK
 * (prc_work_order_billings), cermin persis labor_claim_id (migrasi 001125).
 *
 * KOLOM BARU, BUKAN PEMAKAIAN ULANG subcontract_claim_id/labor_claim_id —
 * alasan 001125 berlaku utuh: satu kolom yang menunjuk beberapa tabel tanpa
 * diskriminator adalah angka yang tak bisa diaudit, dan setiap pembaca kolom
 * lama akan diam-diam men-join ke tabel orang lain ketika id-nya kebetulan
 * sama. Satu tabel sumber = satu kolom FK. Lintas-modul tanpa constraint
 * sesuai CONVENTIONS §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('work_order_billing_id')->nullable()->after('labor_claim_id');
            $table->index('work_order_billing_id');
        });
    }

    public function down(): void
    {
        Schema::table('fin_ap_bills', function (Blueprint $table): void {
            $table->dropIndex(['work_order_billing_id']);
            $table->dropColumn('work_order_billing_id');
        });
    }
};
