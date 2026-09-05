<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL: "satu laporan per proyek per hari, baris HIDUP saja" lewat kolom
 * generated — padanan indeks unik parsial SQLite di 000721 dan 000742
 * (Fase 0, T0.2).
 *
 * MASALAHNYA. MySQL tidak punya indeks parsial. Indeks penuh
 * UNIQUE(project_id, report_date) dari 000720 menghitung baris terhapus lunak,
 * sementara validasi (UniqueDailyReportDate, HseDailyService) mengabaikannya:
 * hapus laporan, catat ulang hari yang sama → validasi lolos, indeks menolak,
 * 500 permanen (prosa 000721). Di SQLite keduanya disatukan dengan
 * `WHERE deleted_at IS NULL` pada indeks; di MySQL disatukan di sini dengan
 * kolom yang NULL persis untuk baris terhapus:
 *
 *     live_key TINYINT AS (IF(deleted_at IS NULL, 1, NULL)) STORED
 *     UNIQUE (project_id, report_date, live_key)
 *
 * NULL tidak pernah sama dengan NULL di indeks unik MySQL, jadi baris terhapus
 * — berapa pun jumlahnya — tidak pernah bertabrakan; dua baris hidup untuk
 * (proyek, tanggal) yang sama bertabrakan di (…, 1). STORED, bukan VIRTUAL,
 * karena kolom generated dalam indeks unik harus dapat dibaca langsung dari
 * baris saat penegakan, dan nilainya ikut soft-delete/restore tanpa satu pun
 * baris kode aplikasi: server yang menghitungnya ulang setiap UPDATE
 * deleted_at. Aplikasi tidak pernah menulis live_key (Resource tidak
 * memancarkannya; alat salin-data harus melewatkannya — kolom generated
 * menolak INSERT eksplisit).
 *
 * URUTAN DDL di prj_daily_reports: indeks baru dipasang DULU, baru indeks
 * lama dijatuhkan — FK project_id → prj_projects butuh satu indeks yang
 * diawali project_id sepanjang waktu, dan MySQL menolak DROP yang membuatnya
 * yatim. prj_hse_daily tidak pernah punya indeks penuh (000742 melewatkannya
 * di MySQL), jadi hanya ditambah.
 *
 * SQLite: migrasi ini tidak berbuat apa-apa — indeks parsialnya sudah ada.
 * Tidak ada data yang ditulis ulang.
 */
return new class extends Migration
{
    private const TABLES = ['prj_daily_reports', 'prj_hse_daily'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->tinyInteger('live_key')->nullable()
                    ->storedAs('IF(`deleted_at` IS NULL, 1, NULL)')
                    ->after('deleted_at');
                $blueprint->unique(['project_id', 'report_date', 'live_key'], "{$table}_live_unique");
            });
        }

        // 000720 made this a full unique index; 000721 (SQLite-only) replaced
        // it with a partial one. On MySQL it is still the full index.
        $legacy = 'prj_daily_reports_project_id_report_date_unique';

        if (collect(Schema::getIndexes('prj_daily_reports'))->contains('name', $legacy)) {
            Schema::table('prj_daily_reports', function (Blueprint $blueprint) use ($legacy): void {
                $blueprint->dropUnique($legacy);
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('prj_daily_reports', function (Blueprint $blueprint): void {
            $blueprint->unique(['project_id', 'report_date']);
        });

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique("{$table}_live_unique");
                $blueprint->dropColumn('live_key');
            });
        }
    }
};
