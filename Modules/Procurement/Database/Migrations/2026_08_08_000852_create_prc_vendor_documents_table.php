<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Register dokumen prakualifikasi vendor (temuan #35 dan #69 — satu register).
 *
 * Sampai sekarang prc_vendors hanya menyimpan NPWP/SPPKP sebagai teks: tidak
 * ada NIB/SIUP/SBU/SKK, dan tidak ada SATU pun kolom tanggal kedaluwarsa di
 * seluruh modul Procurement. Harga telatnya tertulis di config/erp.php
 * (PP 9/2022): PPh final pelaksanaan bersertifikat 2,65% vs 4,00% tanpa —
 * pph_scheme SPK dipilih manual tanpa bukti sertifikat yang masih berlaku,
 * dan SBU subkon yang kadaluarsa di proyek pemerintah bisa menggugurkan
 * pembayaran.
 *
 * is_mandatory adalah gigi gate-nya: hanya dokumen yang DITANDAI wajib dan
 * lewat masa berlakunya yang memblokir pengajuan PO/SPK
 * (VendorQualificationService). Register yang belum diisi tidak memblokir —
 * memblokir seluruh vendor pada hari pertama berarti setiap PO butuh
 * override, dan gate seperti itu langsung dimatikan orang.
 *
 * Perpanjangan adalah UPDATE valid_until; dokumen yang berhenti diurus
 * di-soft-delete supaya pengingatnya berhenti — pola yang sama dengan
 * hr_certificates. NULL valid_until berarti tidak kedaluwarsa (NPWP tidak
 * punya masa berlaku).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prc_vendor_documents', function (Blueprint $table): void {
            $table->id();
            // restrict, bukan cascade: menghapus vendor tidak boleh diam-diam
            // memusnahkan bukti prakualifikasi yang pernah meloloskan PO/SPK.
            $table->foreignId('vendor_id')->constrained('prc_vendors')->restrictOnDelete();
            $table->string('doc_type', 30); // nib | siup | npwp | sppkp | sbu_konstruksi | skk | principal | akta | lainnya
            $table->string('name', 160); // "SBU Konstruksi BG007 — Bangunan Gedung"
            $table->string('number', 100)->nullable();
            $table->string('issuer', 160)->nullable(); // OSS / LPJK / nama principal
            $table->date('issued_date')->nullable();
            // Berlaku s/d: masih sah PADA hari terakhirnya, kedaluwarsa mulai
            // hari berikutnya — bacaan yang sama dengan register jaminan dan
            // deadline-watch (valid_through_end).
            $table->date('valid_until')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('vendor_id');
            $table->index('doc_type');
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prc_vendor_documents');
    }
};
