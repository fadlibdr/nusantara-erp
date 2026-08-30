<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: satu baris BIAYA pada lembar TKDN, ditandai baris penawaran yang
 * ditanggungnya.
 *
 * Kolomnya adalah tabel-tabel Permenperin 35/2025 Lampiran IV huruf B, apa
 * adanya — bukan satu kolom "persen dalam negeri" yang bisa diketik:
 *
 *   tenaga kerja  → nationality (wni/wna)                     100% / 0%
 *   alat kerja    → made_in × owned_by (+ domestic_share_pct) tabel 6 baris
 *   jasa umum     → provider_origin (dn/ln)                   100% / 0%
 *
 * Sebuah kolom persen yang diketik adalah persis cara sebuah lembar TKDN
 * berbohong tanpa ada yang salah hitung. Faktornya diturunkan TkdnService dari
 * kolom-kolom di atas, dan kolom yang tidak relevan bagi cost_group-nya
 * disimpan NULL (service menolak yang salah pasang, dengan 422 yang menyebut
 * kolomnya).
 *
 * quotation_item_id cascade, bukan nullOnDelete: bila baris penawaran yang
 * diuraikan biayanya dihapus, uraian itu ikut hilang. Baris biaya yatim yang
 * tetap tinggal akan diam-diam menggemukkan penyebut sebuah klaim TKDN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tkdn_worksheet_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worksheet_id')->constrained('crm_tkdn_worksheets')->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained('crm_quotation_items')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('cost_group', 20);                   // TkdnCostGroup
            $table->string('description', 250);
            $table->decimal('amount', 18, 2)->default(0);       // biaya komponen (rupiah)
            $table->string('nationality', 3)->nullable();       // TkdnNationality — tenaga kerja
            $table->string('made_in', 2)->nullable();           // TkdnOrigin — alat kerja: dibuat di
            $table->string('owned_by', 10)->nullable();         // TkdnOwnership — alat kerja: dimiliki
            $table->decimal('domestic_share_pct', 8, 4)->nullable(); // hanya untuk ln + campuran
            $table->string('provider_origin', 2)->nullable();   // TkdnOrigin — jasa umum
            $table->timestamps();

            $table->index(['worksheet_id', 'quotation_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tkdn_worksheet_items');
    }
};
