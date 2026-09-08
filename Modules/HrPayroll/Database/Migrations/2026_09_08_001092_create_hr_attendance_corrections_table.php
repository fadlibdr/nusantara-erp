<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi absensi — TAMBAH-SAJA (F-4).
 *
 * Sejak absensi bisa diisi orangnya sendiri dari ponsel, mengubah satu baris
 * bukan lagi "kerani membetulkan ketikannya sendiri": ia menimpa apa yang
 * seorang karyawan catatkan tentang dirinya. Baris di sini adalah nilai
 * SEBELUMNYA plus alasan tertulis, jadi angka lama tetap terbaca sesudah
 * dikoreksi dan pertanyaan "siapa mengubah jam pulang saya, kapan, kenapa"
 * punya jawaban.
 *
 * Tidak ada rute update maupun delete untuk tabel ini. Tambah-saja adalah
 * keseluruhan gunanya: log yang bisa diedit tidak membuktikan apa pun.
 *
 * `source` memisahkan tiga pintu yang bisa mengubah satu baris — 'update'
 * (pengawas lewat PUT, alasan WAJIB diketik), 'bulk' (lembar kerani dikirim
 * ulang, alasannya ditulis sistem karena memaksa 40 alasan per lembar berarti
 * kerani berhenti memakai layarnya), dan 'clock' (jam pulang kedua menimpa
 * yang pertama). Tanpa kolom ini ketiganya tampak sama di log dan pembacanya
 * tidak bisa tahu mana yang keputusan manusia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_corrections', function (Blueprint $table): void {
            $table->id();
            /*
             * restrictOnDelete, BUKAN cascade (verifikasi F-4).
             *
             * Cascade menjadikan seluruh gunanya bohong: `hr_attendances` tidak
             * memakai softDeletes, jadi satu DELETE dari pemegang hr.delete
             * menghapus barisnya BESERTA setiap jejak koreksinya — dan pemegang
             * hr.delete adalah persis orang yang punya alasan menghapusnya.
             * Log tambah-saja yang bisa dimusnahkan tidak membuktikan apa pun,
             * sama seperti log yang bisa diedit.
             *
             * Penjaga basis data ini adalah lapis KEDUA: AttendanceController
             * menjawab 422 dengan kalimat sebelum penghapusannya sampai ke
             * sini. Lapis ini yang menangkap jalur lain — tinker, impor,
             * perintah artisan — yang tidak lewat controller.
             */
            $table->foreignId('attendance_id')->constrained('hr_attendances')->restrictOnDelete();
            $table->string('field', 40);
            // Nilai disimpan sebagai teks: kolom yang dikoreksi bisa enum,
            // tanggal-waktu, atau id proyek, dan log yang harus tahu tipe apa
            // yang sedang dicatatnya adalah log yang pecah saat kolom baru
            // ditambahkan. NULL berarti kolomnya memang kosong sebelumnya.
            $table->string('old_value', 200)->nullable();
            $table->string('new_value', 200)->nullable();
            $table->string('reason', 500);
            $table->string('source', 10); // update | bulk | clock
            // users.id — nullable karena akun bisa dihapus, dan log yang ikut
            // hilang bersama akunnya tidak membuktikan apa pun.
            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->timestamps();

            $table->index(['attendance_id', 'id'], 'hr_attendance_corrections_row_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_corrections');
    }
};
