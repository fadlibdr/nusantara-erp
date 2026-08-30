<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DocumentImportService;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Projects\Enums\DailyReportRole;
use Modules\Projects\Enums\Weather;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P8 kriteria #10 / D12 — laporan harian warisan (layout XLS korpus) masuk
 * lewat registri document-import, mendarat lewat DailyReportService seperti
 * entri manual: manpower_count diturunkan dari rincian, satu laporan per
 * (proyek, tanggal), dan TIDAK ada efek samping apa pun — laporan harian
 * memang tidak memposting jurnal atau stok, dan uji ini memakunya.
 *
 * Fixture: tests/fixtures/import-warisan/laporan-harian.xlsx — pemetaan kolom
 * terdokumentasi di docs/IMPOR-WARISAN.md §1.
 */
class DailyReportImportTest extends ErpTestCase
{
    private function imports(): DocumentImportService
    {
        return app(DocumentImportService::class);
    }

    private function fixture(): string
    {
        return base64_encode((string) file_get_contents(
            base_path('tests/fixtures/import-warisan/laporan-harian.xlsx'),
        ));
    }

    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-02-01',
        ]);
    }

    private function item(): Item
    {
        $category = ItemCategory::query()->firstOrCreate(['code' => 'CAT-UMUM'], ['name' => 'Material Umum']);

        return Item::query()->create([
            'code' => 'ITM-01',
            'name' => 'Beton ready mix K-300',
            'category_id' => $category->id,
            'unit' => 'm3',
            'item_type' => 'material',
            'is_active' => true,
        ]);
    }

    public function test_the_legacy_sheet_lands_as_one_daily_report_with_its_line_tables(): void
    {
        $this->project();
        $this->item();

        $journals = DB::table('fin_journals')->count();
        $ledger = DB::table('inv_stock_ledger')->count();

        $result = $this->imports()->commit('daily-reports', 'laporan-harian.xlsx', $this->fixture());

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['created']);

        $report = DailyReport::query()->with(['manpower', 'equipment', 'receipts', 'materials'])->sole();
        $this->assertSame('2026-03-01', $report->report_date->toDateString());
        $this->assertSame(Weather::Cerah, $report->weather_am);
        $this->assertSame(Weather::Hujan, $report->weather_pm);
        $this->assertSame('Pengecoran kolom lantai 2 zona A', $report->activities);
        $this->assertSame('08:00', substr((string) $report->work_start, 0, 5));

        // manpower_count DITURUNKAN dari rincian (12 + 8), bukan diketik.
        $this->assertSame(20, (int) $report->manpower_count);
        $this->assertSame(
            [DailyReportRole::MandorSipil, DailyReportRole::Produksi],
            $report->manpower->pluck('role_key')->all(),
        );

        $this->assertSame('Concrete pump', $report->equipment[0]->description);
        $this->assertSame('Besi beton D16', $report->receipts[0]->description);
        $this->assertSame(2000.0, (float) $report->receipts[0]->qty_received);
        $this->assertSame(12.0, (float) $report->materials[0]->qty_used);

        // Penanda sumber: dokumen warisan menyebut berkas asalnya.
        $this->assertSame('laporan-harian.xlsx', $report->import_source);

        // Inert: tidak ada jurnal dan tidak ada mutasi stok yang lahir.
        $this->assertSame($journals, DB::table('fin_journals')->count());
        $this->assertSame($ledger, DB::table('inv_stock_ledger')->count());
    }

    public function test_a_second_report_on_the_same_project_and_date_is_refused_by_name(): void
    {
        $this->project();
        $this->item();

        $this->imports()->commit('daily-reports', 'laporan-harian.xlsx', $this->fixture());
        $result = $this->imports()->commit('daily-reports', 'laporan-harian-ulang.xlsx', $this->fixture());

        $this->assertSame(0, $result['created']);
        $existing = DailyReport::query()->sole();
        $messages = implode(' ', $result['documents'][0]['errors'] ?? []);
        $this->assertStringContainsString($existing->code, $messages);
        $this->assertStringContainsString('01-03-2026', $messages);
        $this->assertSame(1, DailyReport::query()->count());
    }
}
