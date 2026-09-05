<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6: formulir K3 harian (FM-10-13, cetak F/K3H) — toolbox meeting, APD per
 * kategori, temuan & tindak lanjut. Sebelum ini seluruh permukaan K3 harian
 * adalah prj_daily_reports.safety_notes, satu kolom prosa (laporan deviasi v2
 * Bagian 3.9).
 *
 * TAUTAN KE LAPORAN HARIAN. daily_report_id menunjuk laporan harian PROYEK DAN
 * TANGGAL YANG SAMA — nullable, karena formulir K3 hari itu harus tetap bisa
 * dicatat sekalipun laporan hariannya belum (atau tidak pernah) dibuat.
 * Tautannya diselesaikan HseDailyService dari (project_id, report_date), tidak
 * pernah diketik klien; laporan harian yang lahir belakangan menaut-balik dari
 * DailyReportService. Keduanya satu modul — itulah alasan HSE tinggal di
 * Projects, bukan Quality: Projects tidak boleh bergantung ke Quality
 * (ARCHITECTURE.md), jadi taut-balik dari laporan harian hanya legal di sini.
 *
 * APD ADALAH BARIS DATA, BUKAN KOLOM. Daftar kategori APD sebuah kontraktor
 * terbuka (helm, rompi, sepatu, sarung tangan, kacamata, harness, masker, …)
 * dan FM-10-13 tiap perusahaan menyusunnya berbeda — kolom per kategori akan
 * membeku pada daftar hari ini. Kategori yang TIDAK pernah dicatat tidak punya
 * baris; pada lembar cetak selnya BERGARIS, bukan 0 (aturan kejujuran §13.5):
 * "tidak dihitung" dan "dihitung, hasilnya nol" adalah dua pernyataan berbeda.
 *
 * Indeks unik (project_id, report_date) PARSIAL — baris hidup saja — pelajaran
 * migrasi 000721: validasi mengabaikan baris terhapus lunak, indeks penuh
 * tidak, dan selisihnya adalah 500 permanen untuk hari yang pernah dihapus.
 *
 * CABANG DRIVER (5 Sep 2026, Fase 0 T0.2). Pernyataan indeks parsial di bawah
 * adalah dialek SQLite (pengenal berkutip-ganda + WHERE pada indeks) dan
 * tidak pernah dijalankan di MySQL — belum ada MySQL di deployment mana pun
 * sebelum Fase 0. Di MySQL tabel dibuat TANPA indeks itu dan migrasi 000746
 * memasang UNIQUE(project_id, report_date, live_key) atas kolom generated.
 * Cabang ditambahkan, migrasi tidak ditulis ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_hse_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // HSE/{Y}/{M2}/{N4}
            $table->foreignId('project_id')->constrained('prj_projects');
            $table->date('report_date');
            // Laporan harian proyek+tanggal sama; nullable — lihat prosa kelas.
            $table->foreignId('daily_report_id')->nullable()->constrained('prj_daily_reports');
            $table->string('toolbox_topic', 200)->nullable();
            $table->json('toolbox_attendees')->nullable(); // daftar nama peserta
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // users — indexed, no FK
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
        });

        // Satu formulir K3 per proyek per hari — baris HIDUP saja (pola 000721).
        // MySQL: 2026_09_05_000746_add_live_key_unique_for_mysql.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX "prj_hse_daily_project_id_report_date_unique" '
                .'ON "prj_hse_daily" ("project_id", "report_date") WHERE "deleted_at" IS NULL'
            );
        }

        Schema::create('prj_hse_daily_apd', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hse_daily_id')->constrained('prj_hse_daily')->cascadeOnDelete();
            $table->string('category', 60); // helm, rompi, sepatu, harness, … — data, bukan kolom
            $table->unsignedInteger('qty');
            $table->timestamps();

            // Satu baris per kategori per hari — baris kedua "helm" akan
            // menjadi dua klaim atas hitungan yang sama.
            $table->unique(['hse_daily_id', 'category']);
        });

        Schema::create('prj_hse_daily_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hse_daily_id')->constrained('prj_hse_daily')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('finding', 300);        // temuan
            $table->string('follow_up', 300)->nullable(); // tindak lanjut
            $table->timestamps();

            $table->index('hse_daily_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_hse_daily_findings');
        Schema::dropIfExists('prj_hse_daily_apd');
        Schema::dropIfExists('prj_hse_daily');
    }
};
