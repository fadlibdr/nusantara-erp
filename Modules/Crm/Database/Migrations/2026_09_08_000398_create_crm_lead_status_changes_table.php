<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat tahap prospek (F-3 / T3.5) — append-only.
 *
 * Aturan "mundur wajib beralasan" tidak berarti apa-apa kalau alasannya tidak
 * disimpan dan tidak dibaca siapa pun: sebuah dialog yang menuntut kalimat lalu
 * membuangnya hanyalah pajak klik. Baris-baris di sini adalah tempat kalimat
 * itu tinggal, dan kartu "Riwayat Tahap" di layar prospek adalah tempat ia
 * dibaca.
 *
 * Prospek TIDAK termasuk model yang diaudit (AuditedModels sengaja memuat data
 * induk yang mengarahkan uang, bukan dokumen), jadi tanpa tabel ini perpindahan
 * tahap tidak meninggalkan jejak apa pun di seluruh aplikasi.
 *
 * `source` membedakan dua penulis yang sah: 'pipeline' (layanan tahap, dipanggil
 * layar/papan/API) dan 'quotation' (Tandai Menang/Kalah yang menyeret
 * prospeknya). Tanpa kolom itu, baris "Penawaran Dikirim → Menang" tanpa
 * pengguna terbaca seperti seseorang yang menyunting diam-diam.
 *
 * Append-only: tidak ada updated_at, tidak ada soft delete, dan tidak ada rute
 * yang mengubah atau menghapusnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            // Null hanya untuk baris pembuka bila kelak dicatat; perpindahan
            // selalu punya asal.
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            // maju | mundur | penawaran (LeadMove + keputusan penawaran).
            $table->string('direction', 20);
            $table->string('source', 20)->default('pipeline');
            $table->text('reason')->nullable();
            // Dokumen yang memutuskan, untuk baris 'quotation' — kode QTN-nya,
            // supaya riwayat bisa dibaca tanpa satu query pun ke tabel lain.
            $table->string('document_code', 40)->nullable();
            // Rujukan lintas modul ke users.id: tanpa constrained() (§2).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_status_changes');
    }
};
