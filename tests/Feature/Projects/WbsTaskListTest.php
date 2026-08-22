<?php

namespace Tests\Feature\Projects;

use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET /api/projects/wbs-tasks — the flat leaf listing behind the Inventory
 * issue-form picker.
 *
 * inv_issue_items.wbs_task_id is how a bon's lines are attributed to paket
 * pekerjaan, and it is NULL on 100% of the demo rows for one reason: the SPA
 * had a raw "ID tugas WBS" number box and no list to pick from. This endpoint
 * is the list. It spans every project because the issue form has no dependent
 * lookups, so each row carries picker_label ('PRJ-2026-001 · B.3') as the
 * disambiguator — Inventory's own validation already refuses a package from
 * the wrong project with a 422 in Indonesian.
 */
class WbsTaskListTest extends ErpTestCase
{
    use BaselineFixtures;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->project = $this->grahaProject();
    }

    public function test_the_listing_returns_only_leaves_by_default_each_with_its_picker_label(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/projects/wbs-tasks?per_page=50')
            ->assertOk();

        $rows = collect($response->json('data'));

        // The 8 leaves; the 3 parents (A, B, C) have no place in the picker —
        // a parent carries no BOQ item by construction, so material charged to
        // one could never be compared against an analisa harga satuan.
        $this->assertCount(8, $rows);
        $this->assertSame([], $rows->whereIn('wbs_code', ['A', 'B', 'C'])->values()->all());

        $b3 = $rows->firstWhere('wbs_code', 'B.3');
        $this->assertSame('PRJ-2026-001', $b3['project_code']);
        $this->assertSame('PRJ-2026-001 · B.3', $b3['picker_label']);
    }

    public function test_the_listing_filters_by_project_and_searches_by_code_or_name(): void
    {
        $other = Project::query()->create([
            'code' => 'PRJ-2026-002',
            'name' => 'Instalasi ELV & Data Center Bank Artha Nusantara',
            'type' => 'system_integration',
            'status' => 'active',
            'contract_value' => 9_800_000_000,
        ]);
        $other->wbsTasks()->create([
            'wbs_code' => 'E.1',
            'name' => 'Instalasi CCTV',
            'weight_pct' => 100,
            'progress_pct' => 0,
            'sort_order' => 1,
        ]);

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->getJson("/api/projects/wbs-tasks?project_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.picker_label', 'PRJ-2026-002 · E.1');

        $this->actingAs($admin)
            ->getJson('/api/projects/wbs-tasks?q=B.3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wbs_code', 'B.3');

        // leaf=0 lists the whole structure: 11 rows on PRJ-2026-001 (3 parents
        // + 8 leaves) plus PRJ-2026-002's single task.
        $this->actingAs($admin)
            ->getJson('/api/projects/wbs-tasks?leaf=0&per_page=50')
            ->assertOk()
            ->assertJsonCount(12, 'data');
    }

    /**
     * prj.view, matching the EVM and baseline GETs — and exactly what the
     * seeded warehouse role holds, so the storeman who raises a bon can load
     * the picker while a teknisi with only svc permissions cannot.
     */
    public function test_the_listing_is_gated_on_the_project_view_permission(): void
    {
        $storeman = $this->userWith('prj.view', 'Kepala Gudang');

        $this->actingAs($storeman)
            ->getJson('/api/projects/wbs-tasks')
            ->assertOk();

        $outsider = $this->userWith('svc.view', 'Teknisi Lapangan');

        $this->actingAs($outsider)
            ->getJson('/api/projects/wbs-tasks')
            ->assertForbidden();
    }
}
