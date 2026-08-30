<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 — core_rate_history (D5, opsional; asumsi roadmap: hanya PPN & PPh final).
 *
 * RIWAYAT, bukan sumber angka. Setiap dokumen tetap men-snapshot tarifnya
 * sendiri saat dibuat (crm_quotations.ppn_rate, scm_labor_contracts.pph_rate,
 * dst.) dan snapshot itu TETAP sumber kebenaran — tidak ada satu baris pun di
 * tabel ini yang pernah dibaca untuk menghitung angka dokumen. Yang direkam:
 * tarif efektif berubah dari berapa ke berapa, oleh siapa, kapan — supaya
 * auditor bisa menjawab "PPN 11% di kontrak Maret itu tarif kapan?" tanpa
 * membongkar log Pengaturan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_rate_history', function (Blueprint $table): void {
            $table->id();
            // Kunci Pengaturan yang berubah, mis. 'tax.ppn_rate' atau
            // 'tax.pph_final_construction.pelaksanaan_bersertifikat'.
            $table->string('rate_key', 80);
            // Tarif EFEKTIF (override bila ada, default bila tidak) sebelum
            // dan sesudah tulisan — reset ke default pun terekam jujur.
            $table->decimal('old_rate', 8, 4)->nullable();
            $table->decimal('new_rate', 8, 4)->nullable();
            // users.id — indexed, tanpa FK (pola core_audit_log); null bila
            // perubahan datang dari seeder/CLI tanpa sesi.
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index('rate_key');
            $table->index('changed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_rate_history');
    }
};
