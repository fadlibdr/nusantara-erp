<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 — Opname mandor (menutup Laporan Deviasi v2 §3.10 "Opname mandor /
 * rekap upah" dan §3.11 "Upah tenaga kerja — mandor/harian").
 *
 * qty_this per baris <= sisa (qty kontrak - Σ qty_this klaim APPROVED,
 * dikunci pada labor_contract_item_id — lihat komentar panjang di migrasi
 * scm_labor_contracts untuk mengapa id baris aman DI SINI dan tidak di P3).
 *
 * Potongan kasbon: kasbon_id menunjuk fin_kasbons (lintas-modul, tanpa FK).
 * Klaim hanya MENCATAT niat potong; fakta akuntansinya terjadi saat tagihan
 * AP-nya disetujui — ApBillService mengkredit 1-1370 dan
 * KasbonService::offsetAgainstWageBill mencatat offsetnya pada kasbon.
 * Angka-angka di header (gross/ppn/pph/kasbon/net) adalah kolom TERSIMPAN
 * hasil hitungan LaborClaimService — lembar cetak dan tagihan AP membaca
 * kolom yang sama, bukan menghitung ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scm_labor_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // OPM/{Y}/{RM}/{N4}
            $table->foreignId('labor_contract_id')->constrained('scm_labor_contracts');
            $table->unsignedInteger('claim_no'); // urutan opname per SP3 (1, 2, ...)
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_amount', 18, 2)->default(0); // Σ amount baris (DPP upah periode ini)
            $table->decimal('ppn_amount', 18, 2)->default(0); // 0 kecuali mandor PKP
            $table->decimal('pph_amount', 18, 2)->default(0); // PPh final UMKM atas gross penuh
            $table->unsignedBigInteger('kasbon_id')->nullable(); // fin_kasbons, lintas-modul tanpa FK
            $table->decimal('kasbon_deduction_amount', 18, 2)->default(0); // <= sisa kasbon; <= gross+ppn-pph
            $table->decimal('net_payable', 18, 2)->default(0); // gross + ppn - pph - kasbon
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['labor_contract_id', 'claim_no']);
            $table->index('kasbon_id');
            $table->index('status');
        });

        Schema::create('scm_labor_claim_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('labor_claim_id')->constrained('scm_labor_claims');
            $table->foreignId('labor_contract_item_id')->constrained('scm_labor_contract_items');
            // Σ qty klaim APPROVED saat opname ini DISUSUN — jangkar deteksi
            // basi di approve(): bila jumlah hidupnya sudah bergeser (opname
            // lain disetujui di antaranya), klaim ini wajib diedit & diajukan
            // ulang, bukan disetujui dengan sisa yang sudah kedaluwarsa.
            $table->decimal('qty_prev', 15, 3)->default(0);
            $table->decimal('qty_this', 15, 3);
            $table->decimal('amount', 18, 2); // qty_this x unit_rate baris kontrak
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_labor_claim_items');
        Schema::dropIfExists('scm_labor_claims');
    }
};
