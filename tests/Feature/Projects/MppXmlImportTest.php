<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\BacSource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Services\MppXmlImportService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P8 kriteria #8 — impor MS Project XML (bukan .mpp biner) menjadi pohon
 * prj_wbs_tasks lalu baseline lewat mesin yang SUDAH ada (BaselineService →
 * PlannedCurve), sehingga kurva S impor keluar dari jalur curve() yang sama
 * dengan EvmService.
 *
 * Fixture tests/fixtures/mpp-sample.xml dibuat tangan: empat daun berdurasi
 * 10 + 20 + 30 + 40 = 100 hari (inklusif dua ujung), jadi bobot porsi-durasi
 * bulat — 10%, 20%, 30%, 40% — dan setiap titik kurva di bawah dihitung ulang
 * dengan tangan, bukan disalin dari keluaran mesin.
 */
class MppXmlImportTest extends ErpTestCase
{
    use BaselineFixtures;

    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/mpp-sample.xml'));
    }

    private function bareProject(string $code = 'PRJ-MPP-01'): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => 'Proyek Impor Jadwal',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-04-30',
        ]);
    }

    public function test_the_fixture_imports_as_the_outline_tree_with_duration_share_weights(): void
    {
        $project = $this->bareProject();

        $result = app(MppXmlImportService::class)->import($project, 'jadwal-graha.xml', $this->fixture(), [
            'baseline' => false,
        ]);

        $this->assertSame(6, $result['tasks']);
        $this->assertNull($result['baseline']);

        $roots = $project->wbsTasks()->whereNull('parent_id')->orderBy('sort_order')->get();
        $this->assertSame(['1', '2'], $roots->pluck('wbs_code')->all());
        $this->assertSame('Pekerjaan Persiapan', $roots[0]->name);

        $persiapan = $roots[0]->children()->get();
        $struktur = $roots[1]->children()->get();
        $this->assertSame(['1.1', '1.2'], $persiapan->pluck('wbs_code')->all());
        $this->assertSame(['2.1', '2.2'], $struktur->pluck('wbs_code')->all());

        // Bobot daun = porsi durasi: 10/100, 20/100, 30/100, 40/100 hari.
        $this->assertSame('10.0000', (string) $persiapan[0]->weight_pct);
        $this->assertSame('20.0000', (string) $persiapan[1]->weight_pct);
        $this->assertSame('30.0000', (string) $struktur[0]->weight_pct);
        $this->assertSame('40.0000', (string) $struktur[1]->weight_pct);

        // Induk = jumlah bobot anaknya, pola generateWbsFromBoq.
        $this->assertSame('30.0000', (string) $roots[0]->weight_pct);
        $this->assertSame('70.0000', (string) $roots[1]->weight_pct);

        // Tanggal diambil dari Start/Finish dengan jam dibuang.
        $this->assertSame('2026-02-01', $persiapan[0]->planned_start->toDateString());
        $this->assertSame('2026-02-10', $persiapan[0]->planned_end->toDateString());
        $this->assertSame('2026-03-03', $struktur[1]->planned_start->toDateString());
        $this->assertSame('2026-04-11', $struktur[1]->planned_end->toDateString());
    }

    public function test_the_import_freezes_a_baseline_whose_points_come_out_of_planned_curve(): void
    {
        $project = $this->bareProject();

        $result = app(MppXmlImportService::class)->import($project, 'jadwal-graha.xml', $this->fixture(), [
            'bac_override' => 1_000_000_000.0,
        ]);

        $baseline = $result['baseline'];
        $this->assertInstanceOf(ProjectBaseline::class, $baseline);
        $this->assertSame(0, (int) $baseline->revision_no);
        $this->assertSame(DocumentStatus::Draft, $baseline->status);
        $this->assertSame(BacSource::Override, $baseline->bac_source);

        $points = $baseline->points()->orderBy('seq')->get();
        $this->assertCount(3, $points);

        // 28-02-2026, dihitung tangan: 1.1 selesai (10) + 1.2 selesai 25-02
        // (20) + 2.1 berjalan 13 dari 30 hari (13) + 2.2 belum mulai (0) = 43%.
        $this->assertSame('2026-02-28', $points[0]->period_end->toDateString());
        $this->assertSame(43.0, (float) $points[0]->planned_pct);
        $this->assertSame(430_000_000.0, (float) $points[0]->planned_value);

        // 31-03-2026: 10 + 20 + 30 + (29 dari 40 hari → 29) = 89%.
        $this->assertSame('2026-03-31', $points[1]->period_end->toDateString());
        $this->assertSame(89.0, (float) $points[1]->planned_pct);

        // 30-04-2026: seluruh daun selesai 11-04 → 100%.
        $this->assertSame('2026-04-30', $points[2]->period_end->toDateString());
        $this->assertSame(100.0, (float) $points[2]->planned_pct);
        $this->assertSame(1_000_000_000.0, (float) $points[2]->planned_value);
    }

    public function test_a_project_that_already_has_a_wbs_is_refused_by_name(): void
    {
        $project = $this->bareProject();
        $project->wbsTasks()->create([
            'wbs_code' => 'A.1',
            'name' => 'Pekerjaan lama',
            'weight_pct' => 100,
            'sort_order' => 1,
        ]);

        try {
            app(MppXmlImportService::class)->import($project, 'jadwal.xml', $this->fixture(), ['baseline' => false]);
            $this->fail('Impor ke proyek ber-WBS seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('PRJ-MPP-01', $e->getMessage());
            $this->assertStringContainsString('1 tugas WBS', $e->getMessage());
            $this->assertStringContainsString('A.1', $e->getMessage());
        }

        $this->assertSame(1, $project->wbsTasks()->count());
    }

    public function test_without_a_rap_and_without_an_override_the_baseline_refusal_is_the_service_own_message(): void
    {
        $project = $this->bareProject();

        try {
            app(MppXmlImportService::class)->import($project, 'jadwal.xml', $this->fixture());
            $this->fail('Baseline tanpa RAP dan tanpa override seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum punya RAP', $e->getMessage());
        }

        // Satu transaksi: WBS ikut batal, bukan setengah mendarat.
        $this->assertSame(0, $project->wbsTasks()->count());
    }

    public function test_a_file_that_is_not_ms_project_xml_is_refused_in_indonesian(): void
    {
        $project = $this->bareProject();

        try {
            app(MppXmlImportService::class)->import($project, 'boq.xml', '<html><body>bukan jadwal</body></html>', ['baseline' => false]);
            $this->fail('Berkas non-MS-Project seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('boq.xml', $e->getMessage());
            $this->assertStringContainsString('MS Project', $e->getMessage());
        }

        $this->assertSame(0, $project->wbsTasks()->count());
    }

    public function test_the_endpoint_imports_for_a_user_holding_prj_update(): void
    {
        $project = $this->bareProject();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $this->userWith('prj.update');

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/import-mpp-xml", [
            'filename' => 'jadwal-graha.xml',
            'content' => base64_encode($this->fixture()),
            'bac_override' => 1_000_000_000,
        ]);

        $response->assertOk();
        $this->assertSame(6, $project->wbsTasks()->count());
        $this->assertSame(1, ProjectBaseline::query()->where('project_id', $project->id)->count());
        $this->assertSame(3, (int) DB::table('prj_baseline_points')->count());
    }
}
