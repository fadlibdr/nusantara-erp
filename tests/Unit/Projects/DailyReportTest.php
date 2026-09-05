<?php

namespace Tests\Unit\Projects;

use Illuminate\Validation\ValidationException;
use Modules\Projects\Enums\Weather;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\DailyReportMaterial;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Laporan harian: header + material lines. Quantities follow the decimal(15,3)
 * convention, and the lines are replaced wholesale on update.
 */
class DailyReportTest extends ErpTestCase
{
    use ProjectsFixtures;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    private function makeReport(array $data = []): DailyReport
    {
        return $this->dailyReports()->create(array_merge([
            'project_id' => $this->project->id,
            'report_date' => '2026-03-10',
            'weather_am' => 'cerah',
            'weather_pm' => 'hujan',
            'manpower_count' => 42,
            'activities' => 'Pengecoran kolom lantai 3 zona A.',
            'materials' => [
                ['item_id' => 1, 'qty_used' => 120, 'unit' => 'zak'],
                ['item_id' => 2, 'qty_used' => 8.5, 'unit' => 'm3'],
            ],
        ], $data));
    }

    public function test_a_report_is_created_with_a_numbered_code_and_its_lines(): void
    {
        $report = $this->makeReport();

        $this->assertStringStartsWith('DRP/', $report->code);
        $this->assertSame(2, $report->materials()->count());
        $this->assertSame(42, (int) $report->manpower_count);
        $this->assertSame(Weather::Cerah, $report->weather_am);
        $this->assertSame(Weather::Hujan, $report->weather_pm);
    }

    public function test_material_quantities_are_stored_with_three_decimals(): void
    {
        $report = $this->makeReport([
            'materials' => [
                ['item_id' => 1, 'qty_used' => 12.34567, 'unit' => 'zak'],
            ],
        ]);

        // 12,34567 -> 12,346 (kuantitas decimal(15,3))
        $this->assertSame(12.346, (float) $report->materials()->firstOrFail()->qty_used);
    }

    public function test_materials_are_replaced_wholesale_on_update(): void
    {
        $report = $this->makeReport();

        $this->dailyReports()->update($report, [
            'manpower_count' => 50,
            'materials' => [
                ['item_id' => 3, 'qty_used' => 2, 'unit' => 'roll'],
            ],
        ]);

        $report->refresh();

        $this->assertSame(50, (int) $report->manpower_count);
        $this->assertSame(1, $report->materials()->count());
        $this->assertSame(1, DailyReportMaterial::query()->count());
        $this->assertSame(3, (int) $report->materials()->firstOrFail()->item_id);
    }

    public function test_updating_without_the_materials_key_keeps_the_lines(): void
    {
        $report = $this->makeReport();

        $this->dailyReports()->update($report, ['manpower_count' => 55]);

        $this->assertSame(55, (int) $report->refresh()->manpower_count);
        $this->assertSame(2, $report->materials()->count());
    }

    public function test_two_reports_on_the_same_day_for_one_project_are_rejected(): void
    {
        $this->makeReport();

        // The index refuses the second row; since T0.4 the service answers
        // with the validator's own 422 sentence instead of the QueryException
        // (two requests for the same day at the same moment, burst harness).
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Sudah ada laporan harian untuk proyek ini pada tanggal tersebut.');

        try {
            $this->makeReport(['activities' => 'Laporan kedua di hari yang sama.']);
        } finally {
            $this->assertSame(1, DailyReport::query()->where('project_id', $this->project->id)->count());
        }
    }

    public function test_the_same_day_on_another_project_is_fine(): void
    {
        $other = $this->makeProject(['name' => 'Proyek lain']);

        $this->makeReport();
        $this->dailyReports()->create([
            'project_id' => $other->id,
            'report_date' => '2026-03-10',
            'activities' => 'Penarikan kabel backbone lantai 1.',
            'manpower_count' => 12,
        ]);

        $this->assertSame(2, DailyReport::query()->count());
    }

    public function test_deleting_a_report_soft_deletes_it(): void
    {
        $report = $this->makeReport();

        $this->dailyReports()->delete($report);

        $this->assertNull(DailyReport::query()->find($report->id));
        $this->assertNotNull(DailyReport::withTrashed()->find($report->id));
    }

    public function test_a_report_without_materials_is_allowed(): void
    {
        $report = $this->makeReport(['materials' => []]);

        $this->assertSame(0, $report->materials()->count());
    }
}
