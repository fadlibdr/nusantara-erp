<?php

namespace Tests\Feature\Projects;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\HseDaily;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P6 — formulir K3 harian (FM-10-13, cetak F/K3H): toolbox meeting, APD per
 * kategori sebagai BARIS DATA, temuan & tindak lanjut, dan tautan ke laporan
 * harian proyek+tanggal yang sama.
 *
 * Keputusan tautan yang dipaku di sini: resolusi EAGER dua arah oleh service —
 * HSE daily yang dibuat saat laporan hariannya sudah ada langsung menunjuknya;
 * HSE daily tanpa laporan harian tetap tercatat (tautan null); laporan harian
 * yang lahir BELAKANGAN mengisi tautan itu dari DailyReportService (menaut-
 * balik). Tautan tidak pernah diketik klien.
 */
class HseDailyTest extends ErpTestCase
{
    use BaselineFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function dailyReport(Project $project, string $date): DailyReport
    {
        return DailyReport::query()->create([
            'project_id' => $project->id,
            'report_date' => $date,
            'manpower_count' => 120,
            'activities' => 'Pengecoran plat lantai 5.',
        ]);
    }

    private function payload(Project $project, string $date, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'report_date' => $date,
            'toolbox_topic' => 'Bekerja di ketinggian',
            'toolbox_attendees' => ['Agus Prasetyo', 'Joko Susilo'],
            'apd' => [
                ['category' => 'helm', 'qty' => 120],
                ['category' => 'harness', 'qty' => 14],
            ],
            'findings' => [
                ['finding' => 'Toe board scaffolding lantai 5 belum terpasang', 'follow_up' => 'Dipasang sebelum shift sore'],
            ],
        ], $overrides);
    }

    public function test_an_hse_daily_links_the_same_date_laporan_harian_when_it_exists(): void
    {
        $project = $this->grahaProject();
        $report = $this->dailyReport($project, '2026-03-25');

        $this->actingAs($this->userWith('prj.create'));
        $response = $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-25'))
            ->assertCreated();

        $this->assertSame($report->id, (int) $response->json('data.daily_report_id'));
        $this->assertSame($report->code, $response->json('data.daily_report_code'));
        $this->assertStringStartsWith('HSE/', (string) $response->json('data.code'));
    }

    public function test_an_hse_daily_without_a_laporan_harian_that_day_is_still_recordable(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $response = $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-27'))
            ->assertCreated();

        $this->assertNull($response->json('data.daily_report_id'));
    }

    /** Tautan diselesaikan server dari (proyek, tanggal) — angka kiriman dibuang. */
    public function test_a_typed_daily_report_id_is_ignored(): void
    {
        $project = $this->grahaProject();
        $other = $this->dailyReport($project, '2026-03-20'); // tanggal LAIN

        $this->actingAs($this->userWith('prj.create'));
        $response = $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-27', [
            'daily_report_id' => $other->id,
        ]))->assertCreated();

        $this->assertNull($response->json('data.daily_report_id'));
    }

    public function test_a_laporan_harian_created_later_back_links_the_hse_daily(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $hseId = $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-28'))
            ->assertCreated()
            ->json('data.id');

        $this->assertNull(HseDaily::query()->findOrFail($hseId)->daily_report_id);

        // Laporan harian hari itu lahir belakangan — lewat jalur resminya.
        $reportId = $this->postJson('/api/projects/daily-reports', [
            'project_id' => $project->id,
            'report_date' => '2026-03-28',
            'manpower_count' => 118,
            'activities' => 'Pembesian kolom lantai 6.',
        ])->assertCreated()->json('data.id');

        $this->assertSame((int) $reportId, HseDaily::query()->findOrFail($hseId)->daily_report_id);
    }

    public function test_one_hse_daily_per_project_per_date(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-25'))->assertCreated();

        $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-25'))
            ->assertStatus(422)
            ->assertJsonPath('errors.report_date.0', 'Formulir K3 harian untuk proyek dan tanggal ini sudah ada.');
    }

    public function test_apd_categories_are_data_rows_and_an_unrecorded_category_has_no_row(): void
    {
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $response = $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-25'))
            ->assertCreated();

        $categories = array_column($response->json('data.apd'), 'category');
        $this->assertSame(['helm', 'harness'], $categories);

        // Kategori yang tak pernah dicatat TIDAK punya baris — bukan baris 0.
        $this->assertNotContains('sepatu', $categories);
    }

    /**
     * FM-10-13 tercetak sebagai formulir rumah F/K3H: baris APD yang tercatat
     * tercetak; kategori yang tidak pernah dicatat tidak dicetak 0; lembar
     * tanpa laporan harian tertaut menggarisi selnya (aturan kejujuran §13.5).
     */
    public function test_the_f_k3h_sheet_prints_recorded_rows_and_rules_the_missing_link(): void
    {
        Company::query()->create(['name' => 'PT Nusantara Karya Integrasi']);
        $project = $this->grahaProject();

        $this->actingAs($this->userWith('prj.create'));
        $unlinked = $this->postJson('/api/projects/hse-daily', $this->payload($project, '2026-03-27'))
            ->assertCreated()->json('data.id');

        $html = app(FormPrintService::class)->html('k3-harian', ['id' => $unlinked]);

        $this->assertStringContainsString('FORMULIR K3 HARIAN', $html);
        $this->assertStringContainsString('Form F/K3H', $html);
        $this->assertStringContainsString('Bekerja di ketinggian', $html);
        $this->assertStringContainsString('helm', $html);
        $this->assertStringContainsString('harness', $html);
        $this->assertStringContainsString('Toe board scaffolding lantai 5 belum terpasang', $html);
        // Tidak ada laporan harian hari itu: selnya bergaris, tidak pernah "0"
        // dan tidak pernah kode laporan hari lain.
        $this->assertStringNotContainsString('DRP/', $html);
    }
}
