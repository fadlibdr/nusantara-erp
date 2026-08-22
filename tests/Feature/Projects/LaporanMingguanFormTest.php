<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Services\BaselineService;
use Modules\Projects\Services\LaporanFormService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * LAPORAN MINGGUAN — the landscape DETAIL SCHEDULE / PROGRAM KERJA.
 *
 * The pad is a bar chart drawn by hand: one row per jenis pekerjaan, a bobot
 * column, and week blocks of six day columns (Senin..Sabtu) in which somebody
 * rules a bar. Two of those three things the ERP can answer exactly — the work
 * packages and their weights are prj_wbs_tasks, and the cumulative
 * RENCANA/REALISASI footer is prj_weekly_progress. The bar is the interesting
 * one: prj_baseline_tasks holds a planned_start and planned_end that CANNOT be
 * rewritten once approved, which is the only span in this database worth
 * printing as a commitment.
 *
 * March 2026 is the month under test because the live file's weekly rows stop at
 * minggu 8 (23–29 March). The ISO week beginning 30 March overlaps March and has
 * no row at all, so it is exactly the case the footer must print blank rather
 * than as 0% — a schedule sheet claiming 0% planned and 0% realised for the last
 * week of the month is a claim that the site stopped.
 */
class LaporanMingguanFormTest extends ErpTestCase
{
    use BaselineFixtures;

    /** The live file's minggu 4..8, cumulative. Minggu 9 deliberately absent. */
    private const WEEKLY = [
        [4, '2026-02-23', '2026-03-01', 20.0, 17.0],
        [5, '2026-03-02', '2026-03-08', 30.0, 26.0],
        [6, '2026-03-09', '2026-03-15', 41.0, 36.0],
        [7, '2026-03-16', '2026-03-22', 52.0, 47.0],
        [8, '2026-03-23', '2026-03-29', 62.0, 55.0],
    ];

    private LaporanFormService $forms;

    private FormPrintService $print;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->forms = app(LaporanFormService::class);
        $this->print = app(FormPrintService::class);
        $this->project = $this->grahaProject();

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'city' => 'Jakarta Timur',
            'is_pkp' => true,
        ]);

        foreach (self::WEEKLY as [$no, $from, $to, $planned, $actual]) {
            $this->project->weeklyProgress()->create([
                'week_no' => $no,
                'period_start' => $from,
                'period_end' => $to,
                'planned_pct' => $planned,
                'actual_pct' => $actual,
                'deviation_pct' => round($actual - $planned, 4),
            ]);
        }
    }

    /** Snapshot → submit → approve, by two different people. */
    private function freeze(): ProjectBaseline
    {
        $this->makeRap($this->project, self::RAP_TOTAL, 'approved');

        $service = app(BaselineService::class);
        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');

        $baseline = $service->snapshot($this->project, ['effective_date' => '2026-02-02'], $maker);
        $service->submit($baseline, $maker);

        return $service->approve($baseline, $checker);
    }

    private function render(): string
    {
        return $this->print->html('laporan-mingguan', [
            'id' => $this->project->id,
            'year' => 2026,
            'month' => 3,
        ]);
    }

    private function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $this->assertTrue(@$document->loadHTML($html), 'The laporan mingguan did not render parseable markup.');

        return new \DOMXPath($document);
    }

    private function row(array $body, string $code): array
    {
        foreach ($body['rows'] as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        $this->fail("The schedule prints no row for WBS [{$code}].");
    }

    /**
     * Six ISO weeks overlap March 2026: the one beginning 23 February (which
     * carries 1 March) through the one beginning 30 March.
     */
    public function test_the_month_is_split_into_the_iso_weeks_that_overlap_it(): void
    {
        $body = $this->forms->mingguan($this->project, 2026, 3);

        $this->assertCount(6, $body['weeks']);
        $this->assertSame(['I', 'II', 'III', 'IV', 'V', 'VI'], array_column($body['weeks'], 'roman'));
        $this->assertSame('2026-02-23', $body['weeks'][0]['days'][0]['date']);
        $this->assertSame('2026-03-30', $body['weeks'][5]['days'][0]['date']);

        // Senin..Sabtu — six columns, exactly as the pad has them. Minggu is not
        // a column on this form.
        $this->assertSame(['S', 'S', 'R', 'K', 'J', 'S'], array_column($body['weeks'][0]['days'], 'letter'));
        $this->assertSame('2026-02-28', $body['weeks'][0]['days'][5]['date']);

        // 23-28 appears twice in this month; the February half is marked so the
        // two blocks cannot be read as the same week.
        $this->assertFalse($body['weeks'][0]['days'][0]['inMonth']);
        $this->assertTrue($body['weeks'][4]['days'][0]['inMonth']);
    }

    public function test_it_lists_the_wbs_leaves_with_their_weights_under_their_parents(): void
    {
        $body = $this->forms->mingguan($this->project, 2026, 3);

        $leaves = array_values(array_filter($body['rows'], fn (array $row): bool => ! $row['group']));
        $this->assertSame(
            ['A.1', 'A.2', 'B.1', 'B.2', 'B.3', 'B.4', 'C.1', 'C.2'],
            array_column($leaves, 'code'),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_column($leaves, 'no'));

        $this->assertSame(28.0313, $this->row($body, 'B.2')['weight']);
        $this->assertSame(36.9962, $this->row($body, 'B.3')['weight']);

        // Parents are section headings carrying their rolled-up bobot; they are
        // not numbered and they are not work packages.
        $parent = $this->row($body, 'B');
        $this->assertTrue($parent['group']);
        $this->assertNull($parent['no']);
        $this->assertSame(83.4996, $parent['weight']);

        // Leaf weights close on 100 per project, which is what makes the bobot
        // column add up on the printed sheet.
        $this->assertSame(100.0, $body['weightTotal']);

        $html = $this->render();
        $this->assertStringContainsString('JENIS PEKERJAAN', $html);
        $this->assertStringContainsString('BOBOT', $html);
        $this->assertStringContainsString('28,0313', $html);
    }

    /**
     * The heart of it: cumulative planned and actual per week, straight from
     * prj_weekly_progress, and NOTHING for a week that has no row.
     */
    public function test_rencana_and_realisasi_come_from_weekly_progress_and_a_week_without_a_row_prints_blank(): void
    {
        $body = $this->forms->mingguan($this->project, 2026, 3);

        $this->assertSame([20.0, 30.0, 41.0, 52.0, 62.0, null], array_column($body['weeks'], 'planned'));
        $this->assertSame([17.0, 26.0, 36.0, 47.0, 55.0, null], array_column($body['weeks'], 'actual'));

        // Not a zero, and not a dash a reader could take for one.
        $this->assertNull($body['weeks'][5]['planned']);
        $this->assertNull($body['weeks'][5]['actual']);

        $xpath = $this->dom($this->render());

        foreach (['rencana' => '62,0000', 'realisasi' => '55,0000'] as $band => $lastKnown) {
            $cells = $xpath->query("//tr[@id='bobot-{$band}']/td[@class='wk']");
            $this->assertNotFalse($cells);
            $this->assertSame(6, $cells->count(), "The {$band} footer prints one figure per week block.");
            $this->assertSame($lastKnown, trim($cells->item(4)->textContent));
            $this->assertSame('', trim($cells->item(5)->textContent), "Minggu VI has no {$band} row and must print blank.");
        }
    }

    /**
     * The bar is the approved baseline's planned span and nothing else. The live
     * WBS carries planned_start/planned_end too, but ProgressService can move
     * those at any time — a bar drawn from them is a bar that redraws itself
     * whenever the plan slips, which is the opposite of what a schedule sheet is
     * for.
     */
    public function test_the_planned_span_is_shaded_from_the_approved_baseline(): void
    {
        $this->freeze();

        $body = $this->forms->mingguan($this->project, 2026, 3);

        $this->assertTrue($body['shaded']);

        // A.1 runs 02-02-2026 → 31-03-2026. Minggu I (23–28 Feb) is entirely
        // inside it; minggu VI is 30 Mar, 31 Mar, then April — so the bar stops
        // after two cells.
        $a1 = $this->row($body, 'A.1')['bars'];
        $this->assertCount(36, $a1);
        $this->assertSame(array_fill(0, 6, true), array_slice($a1, 0, 6));
        $this->assertSame([true, true, false, false, false, false], array_slice($a1, 30, 6));

        // B.2 starts 02-03-2026, so February is bare and minggu II is full.
        $b2 = $this->row($body, 'B.2')['bars'];
        $this->assertSame(array_fill(0, 6, false), array_slice($b2, 0, 6));
        $this->assertSame(array_fill(0, 6, true), array_slice($b2, 6, 6));

        $html = $this->render();
        $this->assertStringContainsString('bar', $html);
        $this->assertStringContainsString($body['baseline']->code, $html);
    }

    /** No approved baseline is no bar, and the page says why. */
    public function test_without_an_approved_baseline_no_bar_is_drawn_at_all(): void
    {
        $body = $this->forms->mingguan($this->project, 2026, 3);

        $this->assertFalse($body['shaded']);
        $this->assertNull($body['baseline']);

        foreach ($body['rows'] as $row) {
            $this->assertSame(
                array_fill(0, 36, false),
                $row['bars'],
                "Row [{$row['code']}] drew a bar from a plan nobody has approved.",
            );
        }

        $html = $this->render();
        $this->assertStringContainsString('belum memiliki baseline yang disetujui', $html);

        $shaded = $this->dom($html)->query("//td[@class='hari bar']");
        $this->assertNotFalse($shaded);
        $this->assertSame(0, $shaded->count());
    }

    /**
     * VOLUME comes from the linked BOQ line or from nowhere. Seven of the eight
     * leaves on the live file carry no boq_item_id, and a volume column filled
     * with a dash that reads as zero would be worse than the blank the site
     * clerk expects.
     */
    public function test_volume_is_the_linked_boq_quantity_and_blank_where_there_is_no_link(): void
    {
        $boqId = DB::table('est_boqs')->insertGetId([
            'code' => 'BOQ/2026/0001', 'title' => 'BOQ PRJ-2026-001',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sectionId = DB::table('est_boq_sections')->insertGetId([
            'boq_id' => $boqId, 'section_no' => 'B', 'name' => 'Pekerjaan Struktur', 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('est_boq_items')->insertGetId([
            'boq_id' => $boqId, 'section_id' => $sectionId, 'wbs_code' => 'B.2',
            'description' => 'Beton ready mix K-300', 'qty' => 8200, 'unit' => 'm3',
            'unit_price' => 1_150_000, 'amount' => 9_430_000_000, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->project->wbsTasks()->where('wbs_code', 'B.2')->update(['boq_item_id' => $itemId]);

        $body = $this->forms->mingguan($this->project->refresh(), 2026, 3);

        $this->assertSame(8200.0, $this->row($body, 'B.2')['volume']);
        $this->assertSame('m3', $this->row($body, 'B.2')['unit']);

        $this->assertNull($this->row($body, 'B.3')['volume']);
        $this->assertNull($this->row($body, 'B.3')['unit']);

        // The pad's column says "bulan ini" and the ERP has no monthly split, so
        // the sheet says out loud which volume it is printing.
        $this->assertStringContainsString('volume KONTRAK', implode(' ', $body['handFilled']));
    }

    /**
     * The month's period and the project week span it covers — 23 February is
     * day 22 of the contract, which is week 4; 30 March is day 57, week 9.
     */
    public function test_the_identity_block_names_the_period_and_the_span_of_project_weeks(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('01 Maret 2026 s/d 31 Maret 2026', $html);
        $this->assertStringContainsString('4 s/d 9', $html);
        $this->assertStringContainsString('DETAIL SCHEDULE', $html);
    }

    /**
     * Over the wire the month is chosen by ANY day inside it.
     *
     * The endpoint's whole vocabulary is ?tanggal= — there is no ?month=, and
     * the site asks for "the schedule covering the 15th", not for a month
     * number. A form that read only year/month would silently print the current
     * month for every request the SPA can actually make.
     */
    public function test_the_endpoint_picks_the_month_from_any_day_inside_it(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/laporan-mingguan/{$this->project->id}?tanggal=2026-03-15")
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('01 Maret 2026 s/d 31 Maret 2026', $html);
        $this->assertStringContainsString('MINGGU VI', $html);
        $this->assertStringContainsString('62,0000', $html);
    }
}
