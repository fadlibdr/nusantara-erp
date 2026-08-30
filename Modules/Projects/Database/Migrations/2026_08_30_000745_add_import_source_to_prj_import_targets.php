<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 (kriteria #10, D12) — penanda sumber untuk dokumen yang lahir dari
 * importer warisan: laporan harian dan opname progres ke pemilik.
 *
 * Preseden rumahnya fin_bank_statements.source_format: dokumen yang lahir dari
 * sebuah berkas menyebut berkas itu di barisnya sendiri. Kolom diisi HANYA
 * oleh DocumentImportService (kunci definisi `source_column`), bukan oleh
 * layar mana pun; NULL berarti dokumen dientri manusia lewat layarnya —
 * makna yang benar untuk seluruh baris lama, sehingga nullable tanpa backfill
 * dan aman dijalankan di MySQL berisi data hidup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_daily_reports', function (Blueprint $table): void {
            $table->string('import_source', 160)->nullable()->after('photos');
        });

        Schema::table('prj_progress_measurements', function (Blueprint $table): void {
            $table->string('import_source', 160)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('prj_daily_reports', function (Blueprint $table): void {
            $table->dropColumn('import_source');
        });

        Schema::table('prj_progress_measurements', function (Blueprint $table): void {
            $table->dropColumn('import_source');
        });
    }
};
