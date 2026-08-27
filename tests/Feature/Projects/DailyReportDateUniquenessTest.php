<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Satu laporan per proyek per hari — dan jawabannya 422 berbahasa manusia,
 * bukan 500.
 *
 * Temuan T1 laporan v2: SQLite menyimpan report_date sebagai
 * "2026-03-25 00:00:00", Rule::unique membandingkan string "2026-03-25",
 * keduanya tak pernah sama — validasi lolos dan duplikat pecah di indeks unik
 * basis data dengan HTTP 500. Uji ini MENYIMPAN baris persis seperti SQLite
 * menyimpannya (lewat model, cast 'date' → datetime penuh) lalu mengirim
 * duplikat lewat HTTP, karena di situlah cacatnya hidup.
 */
class DailyReportDateUniquenessTest extends ErpTestCase
{
    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-077',
            'name' => 'Gudang Distribusi Cikarang',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function report(Project $project, string $date): DailyReport
    {
        return DailyReport::query()->create([
            'project_id' => $project->id,
            'report_date' => $date,
            'manpower_count' => 12,
            'activities' => 'Pengecoran kolom lantai 2 zona A',
        ]);
    }

    public function test_a_duplicate_date_is_a_422_in_indonesian_not_a_500(): void
    {
        $project = $this->project();
        $this->report($project, '2026-03-25');

        // Persis bentuk simpanan SQLite yang mengecoh perbandingan string.
        $this->assertStringContainsString(
            '00:00:00',
            (string) DB::table('prj_daily_reports')->value('report_date'),
        );

        $response = $this->actingAs($this->adminUser())->postJson('api/projects/daily-reports', [
            'project_id' => $project->id,
            'report_date' => '2026-03-25',
            'manpower_count' => 8,
            'activities' => 'Lanjutan pengecoran',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['report_date']);
        $this->assertStringContainsString(
            'Sudah ada laporan harian untuk proyek ini pada tanggal tersebut.',
            $response->json('errors.report_date.0'),
        );
    }

    public function test_the_same_date_on_another_project_is_not_a_duplicate(): void
    {
        $this->report($this->project(), '2026-03-25');

        $other = Project::query()->create([
            'code' => 'PRJ-2026-078',
            'name' => 'Kantor Cabang Bekasi',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $this->actingAs($this->adminUser())->postJson('api/projects/daily-reports', [
            'project_id' => $other->id,
            'report_date' => '2026-03-25',
            'manpower_count' => 5,
            'activities' => 'Mobilisasi awal',
        ])->assertCreated();
    }

    public function test_update_keeping_its_own_date_passes_and_stealing_anothers_is_refused(): void
    {
        $project = $this->project();
        $first = $this->report($project, '2026-03-25');
        $second = $this->report($project, '2026-03-26');

        $admin = $this->adminUser();

        // Menyimpan ulang tanggalnya sendiri bukan duplikat.
        $this->actingAs($admin)
            ->putJson("api/projects/daily-reports/{$second->id}", [
                'report_date' => '2026-03-26',
                'activities' => 'Revisi uraian pekerjaan',
            ])->assertOk();

        // Pindah ke tanggal laporan lain pada proyek yang sama: 422, bukan 500.
        $response = $this->actingAs($admin)
            ->putJson("api/projects/daily-reports/{$second->id}", [
                'report_date' => '2026-03-25',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['report_date']);
        $this->assertNotNull($first->fresh());
    }

    /**
     * project_id palsu pada PUT tidak bisa mengecoh aturan menjadi memeriksa
     * proyek yang salah.
     *
     * Versi pertama membaca input('project_id') ?? proyek route model; klien
     * API yang mengirim project_id lain (atau sampah non-numerik) membuat
     * aturan memeriksa proyek yang salah, lolos, lalu pecah 500 di indeks unik
     * proyek yang sebenarnya. Service update tidak pernah memindahkan laporan
     * antar proyek, jadi proyeknya selalu milik route model.
     */
    public function test_a_decoy_project_id_on_update_cannot_fool_the_rule(): void
    {
        $project = $this->project();
        $this->report($project, '2026-03-25');
        $second = $this->report($project, '2026-03-26');

        $other = Project::query()->create([
            'code' => 'PRJ-2026-079',
            'name' => 'Renovasi Kantor Pusat',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->putJson("api/projects/daily-reports/{$second->id}", [
                'project_id' => $other->id,
                'report_date' => '2026-03-25',
            ])->assertStatus(422)->assertJsonValidationErrors(['report_date']);

        $this->actingAs($admin)
            ->putJson("api/projects/daily-reports/{$second->id}", [
                'project_id' => 'abc',
                'report_date' => '2026-03-25',
            ])->assertStatus(422)->assertJsonValidationErrors(['report_date']);
    }

    /**
     * Hari yang laporannya dihapus bisa dicatat ulang.
     *
     * Baris terhapus lunak dulu menduduki slot indeks unik selamanya —
     * validasi (mengabaikan baris terhapus) lolos, indeks (tidak mengabaikan)
     * menolak 500, dan aplikasi ini sengaja tidak punya pemulihan dokumen.
     * Indeks parsial WHERE deleted_at IS NULL membuat indeks dan validasi
     * menanyakan pertanyaan yang sama.
     */
    public function test_a_deleted_days_report_can_be_reentered(): void
    {
        $project = $this->project();
        $report = $this->report($project, '2026-03-25');
        $report->delete();

        $this->actingAs($this->adminUser())->postJson('api/projects/daily-reports', [
            'project_id' => $project->id,
            'report_date' => '2026-03-25',
            'manpower_count' => 7,
            'activities' => 'Pencatatan ulang setelah laporan pertama dihapus',
        ])->assertCreated();

        // Baris terhapusnya tetap ada — riwayat tidak ditulis ulang.
        $this->assertNotNull($report->fresh());
    }

    /** Angka JSON bukan tanggal: 20260325 dulu tersimpan sebagai 1970. */
    public function test_an_integer_report_date_is_refused_not_stored_as_1970(): void
    {
        $project = $this->project();

        $this->actingAs($this->adminUser())->postJson('api/projects/daily-reports', [
            'project_id' => $project->id,
            'report_date' => 20260325,
            'manpower_count' => 5,
            'activities' => 'Uji tanggal angka',
        ])->assertStatus(422)->assertJsonValidationErrors(['report_date']);
    }
}
