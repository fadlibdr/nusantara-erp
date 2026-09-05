<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indeks unik (project_id, report_date) hanya untuk baris HIDUP.
 *
 * Validasi (UniqueDailyReportDate) dan indeks ini harus menanyakan pertanyaan
 * yang sama. Validasi — lama maupun baru — selalu mengabaikan baris terhapus
 * lunak; indeksnya tidak. Akibatnya alur yang wajar (hapus laporan, catat
 * ulang hari yang sama) lolos validasi lalu pecah 500 di indeks, selamanya,
 * karena baris terhapus tetap menduduki slotnya dan aplikasi ini sengaja tidak
 * punya pemulihan dokumen terhapus (PANDUAN-PENGGUNA §14). Indeks parsial
 * membuat keduanya satu suara: duplikat baris hidup tetap ditolak, hari yang
 * pernah dihapus bisa dicatat ulang.
 *
 * SQLite — basis data deployment ini, produksi maupun uji — mendukung indeks
 * parsial. MySQL tidak; port ke MySQL kelak harus meniru lewat kolom
 * generated atau menerima indeks penuh sebagai jaring terakhir di sana.
 * Tidak ada data yang ditulis ulang oleh migrasi ini.
 *
 * CABANG DRIVER (5 Sep 2026, Fase 0 T0.2). Kedua pernyataan di bawah adalah
 * dialek SQLite: pengenal berkutip-ganda (MySQL membacanya sebagai string
 * literal tanpa ANSI_QUOTES) dan klausa WHERE pada indeks. Migrasi ini TIDAK
 * PERNAH dijalankan di MySQL — sampai hari itu tidak ada MySQL di deployment
 * mana pun — jadi cabangnya ditambahkan, bukan ditulis ulang: di MySQL
 * migrasi ini tidak berbuat apa-apa, indeks penuh dari 000720 tetap ada, dan
 * migrasi 000746 menggantinya dengan UNIQUE(project_id, report_date, live_key)
 * atas kolom generated live_key (1 untuk baris hidup, NULL untuk yang
 * terhapus — NULL tidak pernah bertabrakan di indeks unik MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return; // MySQL: lihat 2026_09_05_000746_add_live_key_unique_for_mysql.
        }

        DB::statement('DROP INDEX IF EXISTS "prj_daily_reports_project_id_report_date_unique"');
        DB::statement(
            'CREATE UNIQUE INDEX "prj_daily_reports_project_id_report_date_unique" '
            .'ON "prj_daily_reports" ("project_id", "report_date") WHERE "deleted_at" IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS "prj_daily_reports_project_id_report_date_unique"');
        DB::statement(
            'CREATE UNIQUE INDEX "prj_daily_reports_project_id_report_date_unique" '
            .'ON "prj_daily_reports" ("project_id", "report_date")'
        );
    }
};
