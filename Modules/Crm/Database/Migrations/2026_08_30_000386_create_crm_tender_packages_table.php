<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7: berkas satu lelang — paket tender (Laporan Deviasi v2 Bagian 3.1).
 *
 * Menggantung pada LEAD, bukan pada penawaran: sebuah lelang punya berkas,
 * jadwal dan berita acara aanwijzing SEBELUM ada penawaran, dan sering
 * berakhir tanpa satu pun. lead_id constrained — Crm ke Crm.
 *
 * CHECKLIST DISIMPAN SEBAGAI SNAPSHOT, bukan sebagai daftar kunci. Kolom json
 * ini memuat label dan grup butirnya di samping centangnya, sehingga menyunting
 * config('erp.tender.checklist_template') tidak pernah menulis ulang checklist
 * paket yang sudah diisi. Kunci di luar template ditolak 422 oleh
 * TenderPackageService — daftar periksa yang bisa menumbuhkan butirnya sendiri
 * saat diisi bukan daftar periksa. Idiom json mengikuti prj_hse_daily
 * (toolbox_attendees) dan prc_negotiation_minutes (peserta).
 *
 * BUKAN Approvable, dan itu keputusan: maker-checker paket lelang ini hidup
 * pada PENAWARAN-nya (crm_quotations sudah Approvable). Sebuah siklus kedua di
 * sini akan meminta pemegang crm.approve menyetujui pengajuan yang sama dua
 * kali — alasan yang sama yang menahan PPKB (P5) dan BAPP zona (P3) keluar dari
 * ApprovableDocuments. Ia tetap bernomor (TND) karena berkas butuh identitas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tender_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->string('title', 250);                       // nama paket pekerjaan yang dilelangkan
            $table->string('owner_name', 200)->nullable();       // instansi/pemberi tugas
            $table->string('tender_number', 100)->nullable();    // nomor pengumuman lelang
            $table->date('registered_at')->nullable();           // tanggal pendaftaran
            $table->date('submission_deadline')->nullable();     // batas pemasukan penawaran
            // Berita acara aanwijzing (penjelasan pekerjaan). Satu tanggal dan
            // satu blok catatan: aanwijzing lanjutan yang punya BA sendiri
            // dicatat sebagai BARIS register dokumen, tempat berita acara
            // memang berada.
            $table->date('aanwijzing_date')->nullable();
            $table->text('aanwijzing_notes')->nullable();
            $table->json('checklist')->nullable();               // snapshot, lihat docblock
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // users — indexed, no FK
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
            $table->index('submission_deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tender_packages');
    }
};
