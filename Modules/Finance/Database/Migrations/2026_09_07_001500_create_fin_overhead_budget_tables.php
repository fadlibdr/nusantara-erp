<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OVB — anggaran overhead perusahaan per tahun buku (F-2 / T2.4).
 *
 * BLOK MIGRASI LANJUTAN. Blok Finance 001100–001199 HABIS (001199 terpakai
 * 25 Juli 2026), jadi berkas ini adalah slot pertama blok lanjutan Finance
 * 001500– yang disetujui pemilik (ROADMAP-HASHMICRO §5 baris 5) dan
 * didaftarkan pada CONVENTIONS §2 dalam commit yang sama dengan berkas ini.
 * Tabel §2 itulah sumber kebenaran rentang blok, bukan prosa mana pun.
 *
 * SATU ANGGARAN DISETUJUI PER TAHUN, DITEGAKKAN DI BASIS DATA.
 *
 * Layanan menegakkannya lebih dulu dengan kalimat Indonesia yang menyebut kode
 * anggaran yang sudah berdiri; indeks di bawah ini adalah jaring terakhirnya —
 * dua persetujuan yang tiba bersamaan tidak bisa dua-duanya lolos, karena
 * lockForUpdate() adalah no-op diam di SQLite (alasan yang sama yang membuat
 * prj_baselines memasang UNIQUE(project_id, revision_no)).
 *
 * Dua dialek untuk satu aturan, mengikuti pola yang sudah ada di repo ini
 * (000721 SQLite parsial + 000746 kolom generated MySQL):
 *
 *   SQLite  CREATE UNIQUE INDEX … ON fin_overhead_budgets (period_year)
 *           WHERE status = 'approved' AND deleted_at IS NULL
 *   MySQL   approved_year SMALLINT AS (IF(status = 'approved' AND deleted_at
 *           IS NULL, period_year, NULL)) STORED, UNIQUE (approved_year)
 *
 * NULL tidak pernah sama dengan NULL di indeks unik, jadi draf, yang ditolak
 * dan yang terhapus — berapa pun jumlahnya per tahun — tidak pernah
 * bertabrakan; dua baris DISETUJUI untuk tahun yang sama bertabrakan. STORED,
 * bukan VIRTUAL: kolom generated di dalam indeks unik harus terbaca langsung
 * dari barisnya saat penegakan, dan nilainya ikut berubah sendiri setiap
 * UPDATE status/deleted_at tanpa satu baris kode aplikasi (aplikasi tidak
 * pernah menulis approved_year — Resource tidak memancarkannya, dan kolom
 * generated menolak INSERT eksplisit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_overhead_budgets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique(); // OVB/{Y}/{RM}/{N4} — fallback DocumentNumberService
            $table->unsignedSmallInteger('period_year');
            $table->string('status', 30)->default('draft'); // DocumentStatus
            // Otoritatif adalah baris rinciannya; kolom ini ringkasan yang
            // dihitung ulang layanan setiap kali rinciannya diganti — pola yang
            // sama dengan est_cost_budgets.total_budget.
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            // User semantics (users.id) — app-owned, no DB constraint.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('period_year');
            $table->index('status');
        });

        Schema::create('fin_overhead_budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('overhead_budget_id')->constrained('fin_overhead_budgets')->cascadeOnDelete();
            /*
             * AKUN COA-nya, dan inilah yang membuat realisasi bisa dibaca dari
             * jurnal tanpa memetakan apa pun: yang dianggarkan adalah akun yang
             * DIPILIH pemilik, jadi realisasinya adalah mutasi akun itu juga.
             * Tidak ada daftar "akun overhead" yang dikarang di kode — sebuah
             * daftar seperti itu akan salah pada bagan akun perusahaan berikutnya.
             */
            $table->foreignId('account_id')->constrained('fin_accounts');
            $table->decimal('amount', 18, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            // Satu baris per akun: dua baris untuk akun yang sama membuat
            // "anggaran akun 6-1100" tidak punya satu jawaban.
            $table->unique(['overhead_budget_id', 'account_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX "fin_overhead_budgets_approved_year_unique" '
                .'ON "fin_overhead_budgets" ("period_year") '
                .'WHERE "status" = \'approved\' AND "deleted_at" IS NULL'
            );

            return;
        }

        if (DB::getDriverName() === 'mysql') {
            Schema::table('fin_overhead_budgets', function (Blueprint $table): void {
                $table->smallInteger('approved_year')->nullable()->unsigned()
                    ->storedAs("IF(`status` = 'approved' AND `deleted_at` IS NULL, `period_year`, NULL)")
                    ->after('deleted_at');
                $table->unique('approved_year', 'fin_overhead_budgets_approved_year_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_overhead_budget_lines');
        Schema::dropIfExists('fin_overhead_budgets');
    }
};
