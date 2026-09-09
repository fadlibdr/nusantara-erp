<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-7 — PEMICU SERVIS KEDUA: jam operasi, di samping tanggal.
 *
 * ast_maintenances sudah punya next_due_date sejak migrasi 000530, dan
 * WatchedDeadlines mengawasinya. Tetapi alat berat tidak dirawat menurut
 * kalender: excavator yang menganggur sebulan tidak butuh servis 250 jam,
 * dan yang bekerja dua shift menembusnya dalam dua minggu. Kolom ini adalah
 * pemicu KEDUA — sisi jam dari kartu servis yang sama.
 *
 * DUA PEMICU YANG BERDIRI SENDIRI-SENDIRI, dan itu seluruh maksudnya: yang
 * mana pun tercapai lebih dulu, servisnya jatuh tempo. Sebuah alat bisa
 * jatuh tempo menurut jam sementara tanggalnya masih empat bulan lagi, dan
 * sebaliknya. Tidak ada satu kolom pun di sini yang boleh menyiratkan bahwa
 * yang satu menggantikan yang lain — keduanya nullable, keduanya berdiri
 * sendiri, dan layar mencetak keduanya berdampingan.
 *
 * NULLABLE, DAN NULL BERARTI "BELUM DISETEL" — bukan nol jam. Nol adalah
 * ANGKA (aturan WatchedThresholds: batas tidak pernah disimpulkan dari
 * nilainya), dan sebuah target servis "pada jam ke-0" adalah kalimat yang
 * tidak berarti apa-apa untuk mesin mana pun; MaintenanceStoreRequest
 * menolaknya dengan gt:0 supaya keadaan TANPA_ANGGARAN — "dianggarkan nol" —
 * tidak pernah bisa lahir di sisi jam, tempat ia tidak punya arti.
 *
 * decimal(15,3), BENTUK YANG SAMA PERSIS dengan ast_equipment_logs.hour_meter
 * (migrasi 000543) dan dengan kuantitas mana pun di CONVENTIONS §4: kedua sisi
 * perbandingan "pembacaan >= target" harus punya presisi yang sama, atau
 * pembulatan di salah satu sisi memutuskan sebuah alat lewat servis atau tidak.
 *
 * MAJU-SAJA: satu kolom baru, nullable, tanpa backfill. Tidak ada baris lama
 * yang bisa ditebak targetnya — target jam adalah keputusan mekanik pada kartu
 * servisnya, bukan angka yang bisa disimpulkan sistem dari data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ast_maintenances', function (Blueprint $table): void {
            $table->decimal('next_due_hour_meter', 15, 3)->nullable()->after('next_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('ast_maintenances', function (Blueprint $table): void {
            $table->dropColumn('next_due_hour_meter');
        });
    }
};
