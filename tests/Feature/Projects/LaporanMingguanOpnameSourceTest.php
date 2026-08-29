<?php

namespace Tests\Feature\Projects;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\WeeklyProgress;
use Modules\Projects\Services\LaporanFormService;
use Modules\Projects\Services\MeasurementService;
use Modules\Projects\Services\ProgressService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * F/DS — THE SHEET MUST SAY WHERE ITS REALISASI CAME FROM.
 *
 * Since P3 the JUMLAH BOBOT — REALISASI row is one of two entirely different
 * numbers: the percentage a supervisor typed into progres mingguan, or the
 * value-weighted figure derived from an APPROVED OPNAME
 * (prj_weekly_progress.actual_pct_source says which). They are an estimate and
 * a measurement, they are signed by different people, and on a schedule sheet
 * three parties sign they may not print identically under one footnote that
 * asserts a single provenance.
 *
 * June 2026 opens on a Monday, so its ISO week blocks are the calendar weeks.
 * The month is deliberately MIXED: minggu I keeps the typed percentage (no
 * opname reaches back that far) and minggu III carries the measurement, which
 * is the case a single-sentence footnote cannot describe truthfully.
 *
 * The BOQ is Rp 1.000.000.000 — A.1 galian 1.000 m3 x Rp 200.000 (20 %) and
 * A.2 beton 500 m3 x Rp 1.600.000 (80 %) — so an opname of 500 m3 + 100 m3
 * measures Rp 260.000.000, i.e. 26,0000 % of the contract BY VALUE.
 */
class LaporanMingguanOpnameSourceTest extends ErpTestCase
{
    // BaselineFixtures only for its userWith() — maker and checker are two
    // different people, exactly as ProgressMeasurementActualPctTest composes
    // the same two traits. Its own PRJ-2026-001 is never built here.
    use BaselineFixtures;
    use OpnameFixtures;

    private LaporanFormService $forms;

    private FormPrintService $print;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedOpnameWorld();

        $this->forms = app(LaporanFormService::class);
        $this->print = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'city' => 'Jakarta Timur',
            'is_pkp' => true,
        ]);
    }

    /** Minggu I — 01..07 Juni; minggu III — 15..21 Juni. */
    private function week(int $no, string $start, string $end, float $planned, float $actual): WeeklyProgress
    {
        return app(ProgressService::class)->recordWeekly([
            'project_id' => $this->project->id,
            'week_no' => $no,
            'period_start' => $start,
            'period_end' => $end,
            'planned_pct' => $planned,
            'actual_pct' => $actual,
        ]);
    }

    /** An approved opname closing on 20 June — inside minggu III, after minggu I. */
    private function approvedOpname(): ProgressMeasurement
    {
        $service = app(MeasurementService::class);

        $opname = $service->create([
            'project_id' => $this->project->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-20',
            'items' => [$this->line('A.1', 500), $this->line('A.2', 100)],
        ]);

        $opname->submit($this->userWith('prj.create', 'Pengukur'));

        return $service->approve($opname, $this->userWith('prj.approve', 'Manajer Proyek'));
    }

    private function body(): array
    {
        return $this->forms->mingguan($this->project->refresh(), 2026, 6);
    }

    private function render(): string
    {
        return $this->print->html('laporan-mingguan', [
            'id' => $this->project->id,
            'year' => 2026,
            'month' => 6,
        ]);
    }

    /**
     * Nothing measured: every printed realisasi is a typed percentage, and the
     * footnote may say exactly that and nothing about opname.
     */
    public function test_a_typed_percentage_is_printed_as_a_typed_percentage(): void
    {
        $this->week(1, '2026-06-01', '2026-06-07', 10, 8);

        $week = $this->body()['weeks'][0];

        $this->assertSame(8.0, $week['actual']);
        $this->assertSame(WeeklyProgress::SOURCE_WEEKLY, $week['actualSource']);
        $this->assertNull($week['actualOpname']);
        $this->assertNull($week['actualNote'], 'A typed percentage carries no opname line on the sheet.');

        $footnote = implode(' ', $this->body()['handFilled']);
        $this->assertStringContainsString('progres mingguan', $footnote);
        $this->assertStringNotContainsString('opname', mb_strtolower($footnote));

        $html = $this->render();
        $this->assertStringContainsString('8,0000', $html);
        $this->assertStringNotContainsString('OPN/', $html);
    }

    /**
     * The heart of it: a realisasi that came from an approved opname says so,
     * names the opname, and is NOT printed under a footnote claiming it came
     * from progres mingguan.
     */
    public function test_a_realisasi_derived_from_an_opname_names_the_opname_on_the_sheet(): void
    {
        $this->week(3, '2026-06-15', '2026-06-21', 30, 27);
        $opname = $this->approvedOpname();

        $week = $this->body()['weeks'][2];

        $this->assertSame(26.0, $week['actual'], 'The value-weighted measurement replaced the typed 27 %.');
        $this->assertSame(WeeklyProgress::SOURCE_MEASUREMENT, $week['actualSource']);
        $this->assertSame($opname->code, $week['actualOpname']);
        $this->assertSame($opname->code, $week['actualNote']);

        $html = $this->render();
        $this->assertStringContainsString('26,0000', $html);
        $this->assertStringContainsString($opname->code, $html);

        $footnote = implode(' ', $this->body()['handFilled']);
        $this->assertStringContainsString('OPNAME', mb_strtoupper($footnote));
        $this->assertStringNotContainsString(
            'Baris JUMLAH BOBOT RENCANA dan REALISASI adalah persentase KUMULATIF proyek dari progres mingguan',
            $footnote,
            'The old single-provenance sentence is false over an opname-derived number.',
        );
    }

    /**
     * A month with one of each. The footnote has to describe BOTH, and the
     * sheet has to let the reader tell the two columns apart.
     */
    public function test_a_mixed_month_marks_the_measured_week_and_leaves_the_typed_one_alone(): void
    {
        $this->week(1, '2026-06-01', '2026-06-07', 10, 8);
        $this->week(3, '2026-06-15', '2026-06-21', 30, 27);
        $opname = $this->approvedOpname();

        $weeks = $this->body()['weeks'];

        $this->assertSame(WeeklyProgress::SOURCE_WEEKLY, $weeks[0]['actualSource']);
        $this->assertNull($weeks[0]['actualNote']);
        $this->assertSame(WeeklyProgress::SOURCE_MEASUREMENT, $weeks[2]['actualSource']);
        $this->assertSame($opname->code, $weeks[2]['actualNote']);

        // A week with no progress row at all still prints blank and claims
        // nothing about where its number came from.
        $this->assertNull($weeks[1]['actual']);
        $this->assertNull($weeks[1]['actualSource']);
        $this->assertNull($weeks[1]['actualNote']);

        $footnote = implode(' ', $this->body()['handFilled']);
        $this->assertStringContainsString('OPN', $footnote);
        $this->assertStringContainsString('progres mingguan', $footnote);

        // The mark has to sit on the WEEK IT BELONGS TO: a sheet that prints
        // the opname number under minggu I would say the estimate was measured
        // and the measurement was typed, which is worse than printing neither.
        $document = new \DOMDocument;
        $this->assertTrue(@$document->loadHTML($this->render()));
        $cells = (new \DOMXPath($document))->query("//tr[@id='bobot-realisasi']/td[@class='wk']");

        $this->assertNotFalse($cells);
        $this->assertSame(5, $cells->count(), 'Juni 2026 overlaps five ISO weeks.');

        $typed = trim($cells->item(0)->textContent);
        $measured = trim($cells->item(2)->textContent);

        $this->assertSame('8,0000', $typed);
        $this->assertStringContainsString('26,0000', $measured);
        $this->assertStringContainsString($opname->code, $measured);
    }
}
