<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\Item;
use Modules\Projects\Enums\DailyReportRole;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\LaporanFormService;
use Tests\ErpTestCase;

/**
 * LAPORAN HARIAN PENUH (P0-A): the four FM-10-12 tables print FROM THE
 * DATABASE once their line tables carry rows.
 *
 * LaporanHarianFormTest proves the other half of the same rule — a cell the
 * ERP cannot answer prints as a ruled blank, never as a zero. This class
 * proves that closing a deviation means ADDING THE DATA SOURCE, not loosening
 * that rule: a jabatan row fills itself only when prj_daily_report_manpower
 * holds a row for that role_key, the MATERIAL MASUK columns fill only from
 * prj_daily_report_receipts, and a report shaped like the old data (no line
 * rows anywhere, no work hours) still prints BYTE-IDENTICALLY to the
 * pre-P0-A renderer — the golden fixture in tests/fixtures/ was captured
 * from commit b645a41, before any of this landed.
 */
class LaporanHarianPenuhTest extends ErpTestCase
{
    use BaselineFixtures;

    private LaporanFormService $forms;

    private FormPrintService $print;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forms = app(LaporanFormService::class);
        $this->print = app(FormPrintService::class);
        $this->project = $this->grahaProject();

        // Identical to LaporanHarianFormTest::setUp — and to the throwaway
        // test that captured the golden fixture. The byte-identity proof
        // below depends on this block not drifting.
        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);

        Contract::query()->whereKey($this->project->contract_id)->update([
            'contract_number_customer' => 'SPK/GSP/2026/017',
            'sign_date' => '2026-01-26',
        ]);

        $this->project->forceFill([
            'location' => 'Jl. TB Simatupang Kav. 18',
            'city' => 'Jakarta Selatan',
            'project_manager_id' => $this->employee('Rina Wijaya', 'Project Manager')->id,
            'site_manager_id' => $this->employee('Agus Prasetyo', 'Site Manager')->id,
        ])->save();

        $this->project->refresh();
    }

    private function employee(string $name, string $position): Employee
    {
        return Employee::query()->create([
            'code' => 'EMP-'.str_pad((string) (Employee::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'nik_ktp' => str_pad((string) (Employee::query()->count() + 1), 16, '3', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1985-01-01',
            'ptkp_status' => 'K/1',
            'join_date' => '2020-01-01',
            'employment_type' => 'tetap',
            'position' => $position,
            'department' => 'proyek',
        ]);
    }

    private function report(array $attributes = []): DailyReport
    {
        return DailyReport::query()->create(array_merge([
            'project_id' => $this->project->id,
            'report_date' => '2026-03-25',
            'weather_am' => 'cerah',
            'weather_pm' => 'mendung',
            'manpower_count' => 148,
            'activities' => 'Pengecoran plat & balok lantai 5 zona A (86 m3); pembesian kolom lantai 6 zona B.',
            'obstacles' => 'Antrian truk mixer 1,5 jam akibat pembatasan lalu lintas.',
            'safety_notes' => 'Toolbox meeting pagi; APD lengkap; nihil insiden.',
        ], $attributes));
    }

    private function render(DailyReport $report): string
    {
        return $this->print->html('laporan-harian', ['id' => $report->id]);
    }

    private function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $this->assertTrue(@$document->loadHTML($html), 'The laporan harian did not render parseable markup.');

        return new \DOMXPath($document);
    }

    /** @return list<string> the trimmed text of every cell matching the XPath. */
    private function cells(\DOMXPath $xpath, string $query): array
    {
        $texts = [];

        foreach ($xpath->query($query) as $cell) {
            $texts[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
        }

        return $texts;
    }

    // ------------------------------------------------------------- manpower

    /**
     * A role with a row prints its headcount; its neighbours stay ruled.
     *
     * Rows are created out of pad order on purpose: the printed order belongs
     * to DailyReportRole::cases(), not to insertion order or to the alphabetic
     * order SQLite's unique index would hand back.
     */
    public function test_a_role_with_a_row_prints_its_headcount_while_neighbours_stay_ruled(): void
    {
        $report = $this->report(['manpower_count' => 92]);
        $report->manpower()->create(['role_key' => 'produksi', 'headcount' => 12]);
        $report->manpower()->create(['role_key' => 'mandor_sipil', 'headcount' => 80]);

        $body = $this->forms->harian($report->refresh());
        $byLabel = array_column($body['manpower'], 'count', 'label');

        $this->assertSame(80, $byLabel['Mandor Sipil + Tukang']);
        $this->assertSame(12, $byLabel['Produksi']);
        $this->assertSame(92, $byLabel['TOTAL']);
        $this->assertNull($byLabel['Project Manager'], 'A role without a row is a ruled blank, exactly as before P0-A.');
        $this->assertNull($byLabel['Subkont']);

        $printed = $this->cells($this->dom($this->render($report)), "//table[@id='tenaga-kerja']//tbody/tr/td[2]");

        $this->assertCount(13, $printed, 'Twelve role rows plus TOTAL, filled or not.');
        $this->assertSame('80', $printed[8], 'Mandor Sipil + Tukang is the ninth pad row.');
        $this->assertSame('12', $printed[6], 'Produksi is the seventh pad row.');
        $this->assertSame('92', $printed[12]);
        $this->assertSame('', $printed[0], 'Project Manager has no row and prints blank.');
        $this->assertSame('', $printed[11], 'Subkont has no row and prints blank.');
    }

    /** One source, two consumers: the pad's row text is the enum's labels, in enum order. */
    public function test_the_pad_rows_are_the_enum_cases_in_order(): void
    {
        $body = $this->forms->harian($this->report());
        $labels = array_column($body['manpower'], 'label');

        $expected = array_map(fn (DailyReportRole $role): string => $role->label(), DailyReportRole::cases());
        $expected[] = 'TOTAL';

        $this->assertSame($expected, $labels);
    }

    // ------------------------------------------------------- material masuk

    /**
     * MATERIAL YANG MASUK HARI INI fills its diterima/ditolak columns from
     * prj_daily_report_receipts — arrival facts, NOT the qty_used consumption
     * table, which keeps printing under its own MATERIAL YANG DIPAKAI heading.
     *
     * qty_rejected 0 on a receipt row PRINTS as 0: the row exists because
     * somebody recorded that delivery, so "nothing rejected" is a recorded
     * statement — unlike the untouched-default ambiguity of manpower_count.
     */
    public function test_material_masuk_prints_received_and_rejected_from_receipts(): void
    {
        $report = $this->report();
        $report->receipts()->create([
            'description' => 'Besi Beton D16',
            'qty_received' => 12.5,
            'qty_rejected' => 0.5,
            'unit' => 'ton',
            'rejection_reason' => 'karat permukaan',
        ]);
        $report->receipts()->create([
            'description' => 'Semen PCC 50kg',
            'qty_received' => 200,
            'qty_rejected' => 0,
            'unit' => 'zak',
        ]);

        $body = $this->forms->harian($report->refresh());

        $this->assertCount(2, $body['materialMasuk']);
        $this->assertSame('Besi Beton D16', $body['materialMasuk'][0]['description']);
        $this->assertSame(12.5, $body['materialMasuk'][0]['received']);
        $this->assertSame(0.5, $body['materialMasuk'][0]['rejected']);
        $this->assertSame('karat permukaan', $body['materialMasuk'][0]['reason']);

        $xpath = $this->dom($this->render($report));
        $rows = $xpath->query("//table[@id='material-masuk']//tbody/tr");

        $this->assertSame(5, $rows->count(), 'Two sourced rows, then ruled blanks up to the pad\'s five.');

        $first = $this->cells($xpath, "//table[@id='material-masuk']//tbody/tr[1]/td");
        $this->assertStringContainsString('Besi Beton D16', $first[0]);
        $this->assertStringContainsString('karat permukaan', $first[0]);
        $this->assertSame('12,5 ton', $first[1]);
        $this->assertSame('0,5', $first[2]);

        $second = $this->cells($xpath, "//table[@id='material-masuk']//tbody/tr[2]/td");
        $this->assertSame('200 zak', $second[1]);
        $this->assertSame('0', $second[2], 'A recorded receipt with nothing rejected states 0, it does not shrug.');

        $blankRow = $this->cells($xpath, "//table[@id='material-masuk']//tbody/tr[3]/td");
        $this->assertSame(['', '', ''], $blankRow, 'The rows after the sourced ones stay ruled blanks.');
    }

    // ------------------------------------------------------------ alat-alat

    public function test_alat_alat_prints_equipment_rows(): void
    {
        $report = $this->report();
        $report->equipment()->create(['description' => 'Tower Crane', 'qty' => 1, 'hours' => 8.5]);
        $report->equipment()->create(['description' => 'Concrete Pump', 'qty' => 2]);

        $body = $this->forms->harian($report->refresh());

        $this->assertCount(2, $body['alat']);
        $this->assertSame('Tower Crane', $body['alat'][0]['description']);
        $this->assertSame(8.5, $body['alat'][0]['hours']);
        $this->assertNull($body['alat'][1]['hours']);

        $xpath = $this->dom($this->render($report));
        $rows = $xpath->query("//table[@id='alat-alat']//tbody/tr");

        $this->assertSame(4, $rows->count(), 'Two sourced rows, then ruled blanks up to the pad\'s four.');

        $first = $this->cells($xpath, "//table[@id='alat-alat']//tbody/tr[1]/td");
        $this->assertStringContainsString('Tower Crane', $first[0]);
        $this->assertStringContainsString('8,5 jam', $first[0]);
        $this->assertSame('1', $first[1]);

        $second = $this->cells($xpath, "//table[@id='alat-alat']//tbody/tr[2]/td");
        $this->assertSame('Concrete Pump', $second[0], 'No hours recorded prints no hours — not "0 jam".');
        $this->assertSame('2', $second[1]);
    }

    // --------------------------------------------------------------- uraian

    /**
     * URAIAN PEKERJAAN / PROGRESS / TARGET / HAMBATAN fill from
     * prj_daily_report_activities in sort_order — and only then. PROGRESS and
     * TARGET on a line with no note recorded stay ruled blanks within an
     * otherwise sourced row.
     */
    public function test_uraian_prints_activity_lines_in_sort_order(): void
    {
        $report = $this->report();
        $report->activityLines()->create([
            'description' => 'Pembesian kolom lantai 6 zona B',
            'sort_order' => 2,
        ]);
        $report->activityLines()->create([
            'description' => 'Pengecoran plat & balok lantai 5 zona A',
            'progress_note' => '86 m3 tercor',
            'target_note' => '90 m3',
            'obstacle' => 'Antrian truk mixer 1,5 jam',
            'sort_order' => 1,
        ]);

        $body = $this->forms->harian($report->refresh());

        $this->assertCount(2, $body['uraianRows']);
        $this->assertSame('Pengecoran plat & balok lantai 5 zona A', $body['uraianRows'][0]['description'], 'sort_order, not insertion order.');
        $this->assertSame('86 m3 tercor', $body['uraianRows'][0]['progress']);
        $this->assertSame('90 m3', $body['uraianRows'][0]['target']);
        $this->assertSame('Antrian truk mixer 1,5 jam', $body['uraianRows'][0]['obstacle']);
        $this->assertNull($body['uraianRows'][1]['progress']);

        $xpath = $this->dom($this->render($report));
        $rows = $xpath->query("//table[@id='uraian']//tbody/tr");

        $this->assertSame(4, $rows->count(), 'Two sourced rows, then ruled blanks up to the pad\'s four.');

        $first = $this->cells($xpath, "//table[@id='uraian']//tbody/tr[1]/td");
        $this->assertSame('1', $first[0]);
        $this->assertStringContainsString('Pengecoran plat', $first[1]);
        $this->assertSame('86 m3 tercor', $first[2]);
        $this->assertSame('90 m3', $first[3]);
        $this->assertStringContainsString('Antrian truk mixer', $first[4]);

        $second = $this->cells($xpath, "//table[@id='uraian']//tbody/tr[2]/td");
        $this->assertSame('2', $second[0]);
        $this->assertStringContainsString('Pembesian kolom', $second[1]);
        $this->assertSame('', $second[2], 'A line without a progress note keeps its ruled blank.');
        $this->assertSame('', $second[3]);

        $blanks = $this->cells($xpath, "//table[@id='uraian']//tbody/tr[3]/td");
        $this->assertSame('3', $blanks[0], 'Ruled blanks keep numbering where the sourced rows stopped.');
    }

    // ------------------------------------------------------------ jam kerja

    /**
     * JAM KERJA prints from work_start/work_end and the lost-hours line from
     * lost_hours_reason. The columns are TIME, so a driver may hand back
     * HH:MM:SS — the pad line prints HH:MM either way.
     */
    public function test_jam_kerja_prints_from_work_start_and_end(): void
    {
        $report = $this->report([
            'work_start' => '07:30:00',
            'work_end' => '17:00',
            'lost_hours_reason' => 'hujan deras 2 jam siang hari',
        ]);

        $body = $this->forms->harian($report);

        $this->assertSame('07:30', $body['workHours']['start']);
        $this->assertSame('17:00', $body['workHours']['end']);
        $this->assertSame('hujan deras 2 jam siang hari', $body['workHours']['reason']);

        $html = $this->render($report);

        $this->assertStringContainsString('Pekerjaan dimulai jam 07:30', preg_replace('/\s+/u', ' ', $html));
        $this->assertStringContainsString('s/d jam 17:00', preg_replace('/\s+/u', ' ', $html));
        $this->assertStringContainsString('hujan deras 2 jam siang hari', $html);
    }

    // ------------------------------------------------- footnotes & honesty

    /**
     * The "Diisi manual di lapangan" footnote names only the cells that are
     * still manual for THIS report. A sourced table's sentence disappears; a
     * report sourced everywhere prints no footnote block at all — there is
     * nothing left to warn the clerk about.
     */
    public function test_sourced_tables_drop_their_hand_filled_footnote_sentence(): void
    {
        $report = $this->report(['manpower_count' => 5]);
        $report->manpower()->create(['role_key' => 'produksi', 'headcount' => 5]);

        $body = $this->forms->harian($report->refresh());
        $footnote = implode(' ', $body['handFilled']);

        $this->assertStringNotContainsString('JUMLAH ORANG', $footnote, 'The manpower table is sourced for this report.');
        $this->assertStringContainsString('MATERIAL YANG MASUK', $footnote, 'The receipt table is still manual for this report.');
        $this->assertStringContainsString('ALAT-ALAT', $footnote);
        $this->assertStringContainsString('PROGRESS', $footnote);

        $report->receipts()->create(['description' => 'Semen', 'qty_received' => 10, 'unit' => 'zak']);
        $report->equipment()->create(['description' => 'Vibrator', 'qty' => 2]);
        $report->activityLines()->create(['description' => 'Pengecoran', 'sort_order' => 1]);

        $body = $this->forms->harian($report->refresh()->load(['manpower', 'equipment', 'receipts', 'activityLines']));

        $this->assertSame([], $body['handFilled'], 'Every table is sourced; no cell is left to fill by hand.');
        $this->assertStringNotContainsString('Diisi manual di lapangan', $this->render($report));
    }

    // -------------------------------------------------------- kompat lama

    /**
     * THE COMPAT PROOF: a legacy-shaped report — no line rows, no work hours,
     * only the columns that existed before P0-A — prints BYTE-IDENTICALLY to
     * the pre-P0-A renderer.
     *
     * tests/fixtures/laporan-harian-pra-p0a.html was captured at commit
     * b645a41 (the commit before this paket) by rendering exactly the report
     * built below, then normalising the one wall-clock string on the sheet —
     * the "Dicetak …" footer. If this test fails, the new tables have leaked
     * into a report that predates them: old reports keep their manual
     * manpower_count and their ruled blanks, byte for byte.
     */
    public function test_a_legacy_shaped_report_prints_byte_identically_to_the_pre_p0a_renderer(): void
    {
        $fixture = base_path('tests/fixtures/laporan-harian-pra-p0a.html');
        $this->assertFileExists($fixture, 'The golden fixture is part of this paket; it must not be regenerated from post-P0-A code.');

        $report = $this->report(['code' => 'DRP/2026/03/0002']);

        $categoryId = DB::table('inv_item_categories')->insertGetId([
            'code' => 'MAT', 'name' => 'Material Konstruksi',
            'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
        ]);
        $item = Item::query()->create([
            'name' => 'Ready Mix K-300',
            'category_id' => $categoryId,
            'unit' => 'm3',
            'item_type' => 'material',
        ]);
        $report->materials()->create(['item_id' => $item->id, 'qty_used' => 86, 'unit' => 'm3']);

        $html = preg_replace(
            '/Dicetak .* — Nusantara ERP/u',
            'Dicetak [dinormalisasi] — Nusantara ERP',
            $this->render($report->refresh()),
        );

        $this->assertSame(file_get_contents($fixture), $html);
    }
}
