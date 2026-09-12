<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preset impor per REKENING bank (P-3c, T3c.1).
 *
 * Satu kolom JSON nullable, tanpa backfill: {name, format, mapping (TANPA
 * period/opening/closing — itu milik tiap berkas), expected_header (sel baris
 * judul pada kolom yang dipetakan), header_note, saved_at, saved_by}. Disimpan
 * HANYA dari pemetaan yang baru saja berhasil dipratinjau (tie-out nol atas
 * berkas bank operator sendiri), diterapkan HANYA bila permintaan menyebut
 * use_preset — atau oleh job folder terpantau (T3c.2). Bukan sniffing.
 *
 * Satu preset per rekening, bukan tabel banyak-per-rekening: satu rekening
 * diekspor dari satu kanal bank oleh satu orang; dua tata letak untuk satu
 * rekening adalah dua rekening di layar (LAPORAN P-3c §2). Tidak ada data
 * rahasia di dalamnya — resource memulangkannya apa adanya.
 *
 * Slot ketiga blok lanjutan Finance 001500– (CONVENTIONS §2, tabel blok adalah
 * sumber kebenarannya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bank_accounts', function (Blueprint $table): void {
            $table->json('import_preset')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('fin_bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('import_preset');
        });
    }
};
