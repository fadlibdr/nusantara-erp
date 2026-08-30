<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: lembar hitung TKDN atas SATU penawaran (Permenperin 35/2025).
 *
 * TIDAK ADA KOLOM PERSENTASE DI SINI, dan itu keputusan yang dipertahankan.
 * Setiap paket lain di repo ini menyimpan angka turunannya (initial_score
 * IBPRP, skor tabulasi P2) supaya lembar yang sudah dicetak dan barisnya yang
 * di-query membaca angka yang sama. TKDN adalah kasus sebaliknya: nilainya
 * dikutip pada dokumen penawaran sebagai KLAIM HUKUM, dan satu-satunya cara
 * angka itu bisa berbeda dari baris biayanya adalah bila ia disimpan terpisah
 * dari baris biayanya. Maka ia dihitung TkdnService::summary dari baris yang
 * ada saat itu juga — sebuah persentase yang tidak bisa tidak setuju dengan
 * uraiannya sendiri.
 *
 * Satu penawaran, satu lembar: unique pada quotation_id. Menomori TKD per
 * lembar memberi berkasnya identitas untuk dilampirkan ke dokumen lelang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tkdn_worksheets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('quotation_id')->unique()->constrained('crm_quotations')->cascadeOnDelete();
            // Lelang yang menjadi tujuan lembar ini, bila ada. Nullable: sebuah
            // penawaran swasta dihitung TKDN-nya juga.
            $table->foreignId('tender_package_id')->nullable()->constrained('crm_tender_packages')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // users — indexed, no FK
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tkdn_worksheets');
    }
};
