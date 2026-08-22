<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\Item;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\LaporanFormService;
use Tests\ErpTestCase;

/**
 * LAPORAN HARIAN on the owner's own paper.
 *
 * The whole point of this form is that most of it is NOT in the ERP. The pad the
 * site clerk has filled in for years carries a manpower table broken down by
 * role, a material received/rejected column pair, an equipment list and a
 * progress-versus-target column — and prj_daily_reports holds exactly four free
 * text fields, two weather enums, one scalar headcount and a list of materials
 * CONSUMED. So the property under test is not "the form renders": it is that
 * every cell the ERP cannot answer comes out as a ruled blank rather than as a
 * zero, and that the cells it can answer are not relabelled into something they
 * are not.
 *
 * A zero would be the expensive mistake. "Mandor Sipil: 0" on a signed laporan
 * harian is a statement that no bricklayer was on site that day — the kind of
 * sentence a customer's quantity surveyor deducts money for, and one the ERP has
 * no basis whatsoever for making.
 */
class LaporanHarianFormTest extends ErpTestCase
{
    use BaselineFixtures;

    private LaporanFormService $forms;

    private FormPrintService $print;

    private Project $project;

    private ?DailyReport $memo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forms = app(LaporanFormService::class);
        $this->print = app(FormPrintService::class);
        $this->project = $this->grahaProject();

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);

        // The live file's own SPK reference and signatories, so the identity
        // block is asserted against real values rather than invented ones.
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
            'nik_ktp' => str_pad((string) random_int(1, 999_999_999), 16, '3', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1985-01-01',
            'ptkp_status' => 'K/1',
            'join_date' => '2020-01-01',
            'employment_type' => 'tetap',
            'position' => $position,
            'department' => 'proyek',
        ]);
    }

    /**
     * DRP/2026/03/0002 as it stands in the live file, weather and all.
     *
     * Memoised when called with no overrides: prj_daily_reports is unique on
     * (project_id, report_date) — one site, one day, one report — so a test that
     * reads the model and then renders the sheet must be looking at the same row
     * both times.
     */
    private function report(array $attributes = []): DailyReport
    {
        if ($attributes === [] && $this->memo !== null) {
            return $this->memo;
        }

        $report = DailyReport::query()->create(array_merge([
            'project_id' => $this->project->id,
            'report_date' => '2026-03-25',
            'weather_am' => 'cerah',
            'weather_pm' => 'mendung',
            'manpower_count' => 148,
            'activities' => 'Pengecoran plat & balok lantai 5 zona A (86 m3); pembesian kolom lantai 6 zona B.',
            'obstacles' => 'Antrian truk mixer 1,5 jam akibat pembatasan lalu lintas.',
            'safety_notes' => 'Toolbox meeting pagi; APD lengkap; nihil insiden.',
        ], $attributes));

        return $attributes === [] ? $this->memo = $report : $report;
    }

    private function readyMix(): Item
    {
        $categoryId = DB::table('inv_item_categories')->insertGetId([
            'code' => 'MAT', 'name' => 'Material Konstruksi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Item::query()->create([
            'name' => 'Ready Mix K-300',
            'category_id' => $categoryId,
            'unit' => 'm3',
            'item_type' => 'material',
        ]);
    }

    private function render(?DailyReport $report = null): string
    {
        return $this->print->html('laporan-harian', ['id' => ($report ?? $this->report())->id]);
    }

    private function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $this->assertTrue(@$document->loadHTML($html), 'The laporan harian did not render parseable markup.');

        return new \DOMXPath($document);
    }

    public function test_it_prints_what_the_daily_report_actually_records(): void
    {
        $body = $this->forms->harian($this->report());

        $this->assertSame('Pengecoran plat & balok lantai 5 zona A (86 m3); pembesian kolom lantai 6 zona B.', $body['activities']);
        $this->assertSame('Antrian truk mixer 1,5 jam akibat pembatasan lalu lintas.', $body['obstacles']);
        $this->assertSame('Toolbox meeting pagi; APD lengkap; nihil insiden.', $body['safetyNotes']);

        // The enum maps to the words the pad prints in its PAGI/SORE boxes, not
        // to the stored 'cerah' / 'mendung'.
        $this->assertSame('Cerah', $body['weather']['pagi']);
        $this->assertSame('Mendung', $body['weather']['sore']);

        $html = $this->render();
        $this->assertStringContainsString('LAPORAN HARIAN', $html);
        $this->assertStringContainsString('Antrian truk mixer', $html);
        $this->assertStringContainsString('Cuaca PAGI', $html);
    }

    /**
     * The sheet is dated by the report, not by the day somebody printed it.
     *
     * 2026-02-02 → 2026-03-25 is 51 days, so the report is HARI KE 52 and falls
     * in MINGGU KE 8; the contract runs to 2027-07-31, which is 493 days away.
     */
    public function test_the_identity_block_is_counted_from_the_reports_own_date(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('25 Maret 2026', $html);
        $this->assertStringContainsString('SPK/GSP/2026/017', $html);
        $this->assertStringContainsString('26 Januari 2026', $html);
        $this->assertStringContainsString('493 hari', $html);

        $xpath = $this->dom($html);
        $identity = [];

        foreach ($xpath->query("//table[@class='identitas']//tr") as $row) {
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell->nodeName === 'td') {
                    $cells[] = trim($cell->textContent);
                }
            }

            for ($column = 0; $column + 2 < count($cells); $column += 3) {
                if ($cells[$column] !== '') {
                    $identity[$cells[$column]] = $cells[$column + 2];
                }
            }
        }

        $this->assertSame('52', $identity['HARI KE']);
        $this->assertSame('8', $identity['MINGGU KE']);
    }

    /**
     * The twelve role rows are the reason this test exists.
     *
     * manpower_count is one integer for the whole site. Splitting it across
     * Project Manager, Danlat, Mandor Sipil and the rest would be arithmetic
     * performed on nothing.
     */
    public function test_the_per_role_manpower_rows_are_ruled_blank_and_only_the_total_is_filled(): void
    {
        $body = $this->forms->harian($this->report());

        $labels = array_column($body['manpower'], 'label');
        $this->assertContains('Project Manager', $labels);
        $this->assertContains('Mandor Sipil + Tukang', $labels);
        $this->assertContains('Subkont', $labels);
        $this->assertContains('TOTAL', $labels);

        foreach ($body['manpower'] as $row) {
            if ($row['total']) {
                $this->assertSame(148, $row['count'], 'The TOTAL row is the one number prj_daily_reports holds.');

                continue;
            }

            $this->assertNull(
                $row['count'],
                "Role row [{$row['label']}] carries a number the ERP does not have; it must print as a ruled blank.",
            );
        }
    }

    public function test_the_rendered_page_shows_the_total_and_leaves_every_role_cell_empty(): void
    {
        $cells = $this->dom($this->render())->query("//table[@id='tenaga-kerja']//tbody/tr/td[2]");

        $this->assertNotFalse($cells);
        $this->assertSame(13, $cells->count(), 'The manpower table prints twelve role rows plus TOTAL.');

        $printed = [];

        foreach ($cells as $cell) {
            $printed[] = trim($cell->textContent);
        }

        // Twelve empty cells, then the one headcount the ERP holds. Not "0".
        $this->assertSame(array_fill(0, 12, ''), array_slice($printed, 0, 12));
        $this->assertSame('148', $printed[12]);
    }

    /**
     * prj_daily_report_materials is qty_used. The pad's column pair is "jumlah
     * yang diterima" against "jumlah yang ditolak", which is a goods-receipt
     * fact and lives in Procurement if anywhere — and the rejected quantity
     * lives nowhere at all. Printing consumption under a heading that says
     * receipt is the same lie as inventing the number.
     */
    public function test_materials_are_printed_as_consumed_and_the_received_rejected_table_stays_blank(): void
    {
        $report = $this->report();
        $report->materials()->create(['item_id' => $this->readyMix()->id, 'qty_used' => 86, 'unit' => 'm3']);

        $body = $this->forms->harian($report->refresh());

        $this->assertCount(1, $body['materialsUsed']);
        $this->assertSame('Ready Mix K-300', $body['materialsUsed'][0]['name']);
        $this->assertSame(86.0, $body['materialsUsed'][0]['qty']);
        $this->assertSame('m3', $body['materialsUsed'][0]['unit']);

        // MATERIAL YANG MASUK HARI INI and ALAT-ALAT have no source at all, so
        // they print as empty ruled rows to be filled on site.
        $this->assertGreaterThan(0, $body['blankRows']['materialMasuk']);
        $this->assertGreaterThan(0, $body['blankRows']['alat']);

        $html = $this->render($report);

        $this->assertStringContainsString('MATERIAL YANG DIPAKAI', $html);
        $this->assertStringContainsString('MATERIAL YANG MASUK HARI INI', $html);
        $this->assertStringContainsString('JUMLAH YANG DITERIMA', $html);
        $this->assertStringContainsString('JUMLAH YANG DITOLAK', $html);
        $this->assertStringContainsString('ALAT-ALAT', $html);

        $xpath = $this->dom($html);

        foreach (['material-masuk', 'alat-alat'] as $table) {
            $filled = $xpath->query("//table[@id='{$table}']//tbody//td[normalize-space(text()) != '']");
            $this->assertNotFalse($filled);
            $this->assertSame(0, $filled->count(), "Table [{$table}] printed a value the ERP cannot source.");
        }
    }

    /** No weather recorded is an empty box, not "Cerah" by default. */
    public function test_an_unrecorded_weather_box_is_blank(): void
    {
        $body = $this->forms->harian($this->report(['weather_am' => null, 'weather_pm' => null]));

        $this->assertNull($body['weather']['pagi']);
        $this->assertNull($body['weather']['sore']);
    }

    /**
     * manpower_count is unsigned with a default of 0, so an untouched field and
     * an empty site store the same byte. The sheet must not print them the same.
     */
    public function test_a_zero_headcount_prints_blank_rather_than_zero(): void
    {
        $body = $this->forms->harian($this->report(['manpower_count' => 0]));

        $this->assertNull($body['manpower'][12]['count']);
        $this->assertTrue($body['manpower'][12]['total']);
    }

    /**
     * PROGRESS and TARGET are the two columns nobody can source: the ERP records
     * progress per WBS package and per week, never per day, and holds no daily
     * target at all. The site clerk should not have to wonder which columns are
     * theirs.
     */
    public function test_the_sheet_names_the_columns_that_are_hand_filled(): void
    {
        $body = $this->forms->harian($this->report());
        $footnote = implode(' ', $body['handFilled']);

        $this->assertStringContainsString('PROGRESS', $footnote);
        $this->assertStringContainsString('TARGET', $footnote);
        $this->assertStringContainsString('JUMLAH ORANG', $footnote);
        $this->assertStringContainsString('ALAT-ALAT', $footnote);

        $html = $this->render();
        $this->assertStringContainsString('Diisi manual di lapangan', $html);
        $this->assertStringContainsString('PROGRESS dan TARGET diisi manual', $html);

        // The pad's two working-day sentences, which no column in this database
        // answers, are printed as rules rather than left off.
        $this->assertStringContainsString('Pekerjaan dimulai jam', $html);
    }

    /**
     * ?tanggal= cannot move a laporan harian.
     *
     * The endpoint accepts that parameter for the forms that need it, and this
     * one does not: the report IS a date. Letting the URL win would let anybody
     * reprint DRP/2026/03/0002 as though it were filed in January, counters and
     * all, and the reprint would be indistinguishable from the original.
     */
    public function test_the_url_cannot_redate_a_daily_report(): void
    {
        $report = $this->report();

        $html = $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/laporan-harian/{$report->id}?tanggal=2026-01-05")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('25 Maret 2026', $html);
        $this->assertStringNotContainsString('05 Januari 2026', $html);
        $this->assertStringContainsString($report->code, $html);
    }
}
