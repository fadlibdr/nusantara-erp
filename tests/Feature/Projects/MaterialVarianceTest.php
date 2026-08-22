<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET /api/projects/{project}/material-variance — teori (koefisien AHSP x
 * volume BOQ) versus aktual (bon gudang), the report Package 12 is named after.
 *
 * The estimation fixture rebuilds the live file's own linkage for the two
 * packages that can produce material theory on PRJ-2026-001: B.2 (Ready Mix,
 * BOQ 8.200 m3 x koefisien 1,02 = 8.364 m3 @ Rp 1.150.000) and B.3 (Besi,
 * 948.000 kg x 1,05 = 995.400 kg @ Rp 12.500) — including the kg-vs-btg unit
 * clash on B.3 and the 'Kawat beton' AHSP component that names no inventory
 * item, because those two are what decide the report's shape.
 */
class MaterialVarianceTest extends ErpTestCase
{
    use BaselineFixtures;

    private Project $project;

    private int $semenId;

    private int $besiId;

    private int $readyMixId;

    private int $warehouseId;

    private int $issueSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->project = $this->grahaProject();
        $this->seedItems();
    }

    public function test_theory_follows_ahsp_coefficients_and_actuals_land_on_their_tagged_package(): void
    {
        $this->linkDemoEstimation($this->project);
        $b2 = (int) $this->project->wbsTasks()->where('wbs_code', 'B.2')->value('id');

        // 100 m3 of Ready Mix, tagged per line to B.2, at moving-average cost
        // Rp 1.100.000 — deliberately below the budgeted Rp 1.150.000, so the
        // value variance mixes price and usage while the qty variance does not.
        $this->postIssue($this->project, '2026-07-05', [
            [$this->readyMixId, 100, 1_100_000, $b2],
        ]);

        $admin = $this->adminUser();

        $report = $this->actingAs($admin)
            ->getJson("/api/projects/{$this->project->id}/material-variance?basis=progress")
            ->assertOk()
            ->json('data');

        // Teori B.2: 8.200 x 1,02 = 8.364 m3; sampai progres 65% = 5.436,6 m3
        // x Rp 1.150.000 = Rp 6.252.090.000. Teori B.3: 948.000 x 1,05 =
        // 995.400 kg; sampai progres 60% = 597.240 kg x Rp 12.500 =
        // Rp 7.465.500.000. Total Rp 13.717.590.000.
        $this->assertSame('ok', $report['state']);
        // (float) casts because a whole-value float leaves json_encode with no
        // decimal point and arrives back as an int — same as the Inventory
        // attribution tests treat line amounts.
        $this->assertSame(13717590000.0, (float) $report['summary']['theory_value']);
        $this->assertSame(110000000.0, (float) $report['summary']['actual_value']);
        $this->assertSame(-13607590000.0, (float) $report['summary']['variance_value']);
        $this->assertSame(-99.2, (float) $report['summary']['variance_pct']);
        $this->assertSame(8, $report['summary']['leaf_task_count']);
        $this->assertSame(2, $report['summary']['tasks_with_theory']);

        $rows = collect($report['rows']);
        $this->assertCount(2, $rows);

        $beton = $rows->firstWhere('wbs_code', 'B.2');
        $this->assertSame(5436.6, (float) $beton['theory_qty']);
        $this->assertSame('m3', $beton['theory_unit']);
        $this->assertSame(6252090000.0, (float) $beton['theory_value']);
        $this->assertSame(100.0, (float) $beton['actual_qty']);
        $this->assertSame(110000000.0, (float) $beton['actual_value']);
        $this->assertSame(-5336.6, (float) $beton['variance_qty']);
        $this->assertSame(-6142090000.0, (float) $beton['variance_value']);
        $this->assertNull($beton['note']);
        $this->assertTrue($beton['flagged']);

        // B.3 measures theory in kg while the warehouse stocks besi in btg: a
        // quantity variance in mixed units would be worse than none, so it is
        // null with the note — the rupiah column still carries the theory.
        $besi = $rows->firstWhere('wbs_code', 'B.3');
        $this->assertSame(597240.0, (float) $besi['theory_qty']);
        $this->assertSame('kg', $besi['theory_unit']);
        $this->assertSame('btg', $besi['actual_unit']);
        $this->assertSame(7465500000.0, (float) $besi['theory_value']);
        $this->assertSame('satuan_berbeda', $besi['note']);
        $this->assertNull($besi['variance_qty']);
        $this->assertSame(-7465500000.0, (float) $besi['variance_value']);
        // Zero recorded usage is "belum ditandai", not thrift — never flagged.
        $this->assertFalse($besi['flagged']);

        // Kawat beton (koefisien 0,015 kg/kg, no inventory item) is skipped
        // from theory and NAMED: 948.000 x 0,015 x 60% x Rp 25.000 =
        // Rp 213.300.000 of budgeted money no bon can ever be matched against.
        $this->assertTrue(
            collect($report['warnings'])->contains(
                fn (string $warning): bool => str_contains($warning, 'Kawat beton')
                    && str_contains($warning, 'Rp 213.300.000'),
            ),
            'The unmatchable AHSP component must be named, not silently dropped.',
        );

        // Basis 'full' drops the progress factor: Rp 9.618.600.000 +
        // Rp 12.442.500.000 = Rp 22.061.100.000.
        $full = $this->actingAs($admin)
            ->getJson("/api/projects/{$this->project->id}/material-variance?basis=full")
            ->assertOk()
            ->json('data');

        $this->assertSame('full', $full['basis']);
        $this->assertSame(22061100000.0, (float) $full['summary']['theory_value']);
    }

    public function test_untagged_lines_sit_in_the_unattributed_bucket_and_draft_issues_count_nowhere(): void
    {
        $this->linkDemoEstimation($this->project);

        // The live file's exact bon: ISS/2026/VII/0001, 150 zak semen +
        // 80 btg besi, both lines untagged — Rp 18.740.000.
        $this->postIssue($this->project, '2026-07-05', [
            [$this->semenId, 150, 62_000, null],
            [$this->besiId, 80, 118_000, null],
        ]);

        // A draft bon has moved no stock; if it leaked in, the bucket would
        // read Rp 19.360.000 and the percentage would drop below 100.
        $this->postIssue($this->project, '2026-07-10', [
            [$this->semenId, 10, 62_000, null],
        ], status: 'draft');

        $report = $this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}/material-variance")
            ->assertOk()
            ->json('data');

        $this->assertSame(18740000.0, (float) $report['summary']['unattributed_value']);
        $this->assertSame(2, $report['summary']['unattributed_line_count']);
        $this->assertSame(100.0, (float) $report['summary']['unattributed_issue_pct']);
        // Nothing tagged, so the actual column reads zero — and the 100%
        // above is exactly why that zero must not be read as thrift.
        $this->assertSame(0.0, (float) $report['summary']['actual_value']);

        $lines = $report['unattributed'];
        $this->assertCount(2, $lines);
        $this->assertSame('2026-07-05', $lines[0]['issue_date']);
        $this->assertSame('Semen Portland 50kg', $lines[0]['item_name']);
        $this->assertSame(150.0, (float) $lines[0]['qty']);
        $this->assertSame('zak', $lines[0]['unit']);
        $this->assertSame(9300000.0, (float) $lines[0]['amount']);

        // The theory rows still stand beside the empty actual column, unflagged.
        $beton = collect($report['rows'])->firstWhere('wbs_code', 'B.2');
        $this->assertSame(0.0, (float) $beton['actual_value']);
        $this->assertFalse($beton['flagged']);
    }

    public function test_a_project_without_a_linked_rab_states_the_blind_spot_instead_of_printing_zero_theory(): void
    {
        // No BOQ linked at all — theory is UNKNOWN, which is a different
        // statement from "theory is zero", and the payload must say which.
        $this->postIssue($this->project, '2026-07-05', [
            [$this->semenId, 150, 62_000, null],
            [$this->besiId, 80, 118_000, null],
        ]);

        $report = $this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}/material-variance")
            ->assertOk()
            ->json('data');

        $this->assertSame('no_boq', $report['state']);
        $this->assertStringContainsString('RAB', $report['message']);
        $this->assertNull($report['summary']['theory_value']);
        $this->assertNull($report['summary']['variance_value']);
        $this->assertNull($report['summary']['variance_pct']);
        $this->assertNull($report['theory_source']);
        $this->assertSame(0, $report['summary']['tasks_with_theory']);
        $this->assertSame(8, $report['summary']['leaf_task_count']);
        $this->assertSame([], $report['rows']);

        // The bon that already left the warehouse is still accounted for —
        // a missing RAB is no reason to lose sight of Rp 18.740.000.
        $this->assertSame(18740000.0, (float) $report['summary']['unattributed_value']);
        $this->assertSame(2, $report['summary']['unattributed_line_count']);
    }

    public function test_a_future_report_date_is_refused_and_a_boundary_day_bon_is_counted(): void
    {
        $this->linkDemoEstimation($this->project);
        // Stored as '2026-07-05 00:00:00', the exact shape that turned a raw
        // string compare into an off-by-one-day bug elsewhere in this repo.
        $this->postIssue($this->project, '2026-07-05', [
            [$this->semenId, 150, 62_000, null],
        ]);

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson("/api/projects/{$this->project->id}/material-variance?as_of=".now()->addDay()->toDateString())
            ->assertStatus(422);

        // On the cut-off day itself the bon counts (whereDate, not string <=).
        $onTheDay = $this->actingAs($admin)
            ->getJson("/api/projects/{$this->project->id}/material-variance?as_of=2026-07-05")
            ->assertOk()
            ->json('data');

        $this->assertSame(9300000.0, (float) $onTheDay['summary']['unattributed_value']);

        // One day earlier it does not — and the report admits its limitation:
        // theory still runs on TODAY's package progress.
        $dayBefore = $this->actingAs($admin)
            ->getJson("/api/projects/{$this->project->id}/material-variance?as_of=2026-07-04")
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, (float) $dayBefore['summary']['unattributed_value']);
        $this->assertTrue(
            collect($dayBefore['warnings'])->contains(
                fn (string $warning): bool => str_contains($warning, 'Laporan mundur'),
            ),
        );
    }

    /**
     * prj.view, matching the EVM GET beside it in the routes file: the report
     * names RAB volumes and harga satuan, which the warehouse role may read
     * (it holds prj.view) and a teknisi with only svc permissions may not.
     */
    public function test_the_report_is_gated_on_the_project_view_permission(): void
    {
        $this->actingAs($this->userWith('prj.view', 'Kepala Gudang'))
            ->getJson("/api/projects/{$this->project->id}/material-variance")
            ->assertOk();

        $this->actingAs($this->userWith('svc.view', 'Teknisi Lapangan'))
            ->getJson("/api/projects/{$this->project->id}/material-variance")
            ->assertForbidden();
    }

    // ------------------------------------------------------------- fixtures

    /** The three inventory items the demo bon and AHSP components name. */
    private function seedItems(): void
    {
        $categoryId = DB::table('inv_item_categories')->insertGetId([
            'code' => 'MAT',
            'name' => 'Material Konstruksi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = fn (string $code, string $name, string $unit, float $avgCost): int => DB::table('inv_items')->insertGetId([
            'code' => $code,
            'name' => $name,
            'category_id' => $categoryId,
            'unit' => $unit,
            'avg_cost' => $avgCost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->semenId = $item('ITM-0001', 'Semen Portland 50kg', 'zak', 62_000);
        $this->besiId = $item('ITM-0002', 'Besi Beton D16', 'btg', 118_000);
        $this->readyMixId = $item('ITM-0007', 'Ready Mix K-300', 'm3', 1_100_000);

        $this->warehouseId = (int) DB::table('inv_warehouses')->insertGetId([
            'code' => 'GDG-01',
            'name' => 'Gudang Proyek Graha Sentosa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * BOQ + AHSP for B.2 and B.3 exactly as the live file has them, linked to
     * the project header and to the two WBS leaves.
     */
    private function linkDemoEstimation(Project $project): void
    {
        $boqId = DB::table('est_boqs')->insertGetId([
            'code' => 'BOQ/2026/0001',
            'project_id' => $project->id,
            'title' => 'BOQ Graha Sentosa',
            'status' => 'approved',
            'total' => 48_500_000_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sectionId = DB::table('est_boq_sections')->insertGetId([
            'boq_id' => $boqId,
            'section_no' => 'B',
            'name' => 'Pekerjaan Struktur',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $betonAhspId = DB::table('est_ahsp')->insertGetId([
            'code' => 'A.4.3.1.3',
            'name' => "Membuat 1 m3 beton ready mix K-300 (f'c 26,4 MPa)",
            'unit' => 'm3',
            'category' => 'sipil',
            'unit_price' => 1_494_075,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $besiAhspId = DB::table('est_ahsp')->insertGetId([
            'code' => 'A.4.3.1.10',
            'name' => 'Pembesian 1 kg besi beton ulir',
            'unit' => 'kg',
            'category' => 'sipil',
            'unit_price' => 17_056.6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = fn (int $ahspId, string $type, string $name, ?int $itemId, string $unit, float $coefficient, float $unitPrice) => DB::table('est_ahsp_components')->insert([
            'ahsp_id' => $ahspId,
            'component_type' => $type,
            'name' => $name,
            'item_id' => $itemId,
            'unit' => $unit,
            'coefficient' => $coefficient,
            'unit_price' => $unitPrice,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component($betonAhspId, 'material', 'Ready Mix K-300', $this->readyMixId, 'm3', 1.02, 1_150_000);
        $component($betonAhspId, 'labor', 'Pekerja', null, 'OH', 1.0, 110_000);
        $component($besiAhspId, 'material', 'Besi beton ulir D16', $this->besiId, 'kg', 1.05, 12_500);
        // The component no bon can ever match: material, but item_id null.
        $component($besiAhspId, 'material', 'Kawat beton', null, 'kg', 0.015, 25_000);
        $component($besiAhspId, 'labor', 'Pekerja', null, 'OH', 0.007, 110_000);

        $boqItem = fn (string $wbsCode, string $description, int $ahspId, float $qty, string $unit, float $unitPrice, int $order): int => DB::table('est_boq_items')->insertGetId([
            'boq_id' => $boqId,
            'section_id' => $sectionId,
            'wbs_code' => $wbsCode,
            'description' => $description,
            'ahsp_id' => $ahspId,
            'qty' => $qty,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'amount' => $qty * $unitPrice,
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $b2ItemId = $boqItem('B.2', 'Beton ready mix K-300 kolom, balok & plat', $betonAhspId, 8_200, 'm3', 1_657_950, 1);
        $b3ItemId = $boqItem('B.3', 'Pembesian besi beton ulir', $besiAhspId, 948_000, 'kg', 18_927.4, 2);

        $project->forceFill(['boq_id' => $boqId])->save();
        $project->wbsTasks()->where('wbs_code', 'B.2')->update(['boq_item_id' => $b2ItemId]);
        $project->wbsTasks()->where('wbs_code', 'B.3')->update(['boq_item_id' => $b3ItemId]);
    }

    /**
     * A bon through DB::table, not through the Inventory service: the report
     * only reads these tables, and posting for real would also demand a
     * ledger, stock balances and two users. The issue_date deliberately keeps
     * the ' 00:00:00' suffix the live file's date-cast columns carry.
     *
     * @param  list<array{0: int, 1: float, 2: float, 3: ?int}>  $lines  [item_id, qty, unit_cost, wbs_task_id]
     */
    private function postIssue(Project $project, string $date, array $lines, string $status = 'posted'): int
    {
        $this->issueSequence++;

        $issueId = (int) DB::table('inv_issues')->insertGetId([
            'code' => sprintf('ISS/2026/VII/%04d', $this->issueSequence),
            'warehouse_id' => $this->warehouseId,
            'project_id' => $project->id,
            'issue_date' => $date.' 00:00:00',
            'purpose' => 'Pengecoran kolom lantai 1 zona A',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as [$itemId, $qty, $unitCost, $wbsTaskId]) {
            DB::table('inv_issue_items')->insert([
                'issue_id' => $issueId,
                'item_id' => $itemId,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'amount' => round($qty * $unitCost, 2),
                'wbs_task_id' => $wbsTaskId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issueId;
    }
}
