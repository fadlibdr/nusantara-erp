<?php

namespace Tests\Feature\Inventory;

use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Closing the loop between a material issue and the work package that consumed
 * it — the attribution the material variance report compares against theory.
 *
 * Before this, inv_issues.wbs_task_id was validated as ['nullable', 'integer']
 * and read by nobody: 999999 was accepted, and the demo database carries the
 * column null on 100% of its rows. The report cannot tell an unattributed line
 * from a wrongly attributed one, so the guards below are the load-bearing half
 * of the feature, not the picker.
 *
 * The shape under test is the one real document in the demo: ISS/2026/VII/0001
 * issues 150 zak Semen Portland (pasangan bata, WBS C.1) and 80 btg Besi Beton
 * D16 (pembesian, WBS B.3) on ONE bon — which is why the line, not the header,
 * is the authority.
 */
class IssueWbsAttributionTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $gudang;

    private Item $semen;

    private Item $besi;

    private Project $graha;

    /** WBS "B Pekerjaan Struktur" — a section, therefore a parent, therefore not a work package. */
    private WbsTask $struktur;

    /** WBS "B.3 Pembesian besi beton ulir" — a leaf, carrying BOQ item 5. */
    private WbsTask $pembesian;

    /** WBS "C.1 Pasangan bata, plesteran & finishing arsitektur" — the other leaf on the same bon. */
    private WbsTask $bata;

    /** A leaf of the OTHER project, to prove ids are checked and not merely counted. */
    private WbsTask $cctv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = $this->makeWarehouse('WH-PRJ-2026-001');
        $this->semen = $this->makeItem('Semen Portland 50kg');
        $this->besi = $this->makeItem('Besi Beton D16', ['unit' => 'btg']);

        $this->graha = $this->makeProject('Pembangunan Gedung Kantor Graha Sentosa');

        // BOQ item ids mirror the live file: 5 is the pembesian BOQ row, 7 the
        // pasangan bata row (the one no live prj_wbs_task references — here it
        // IS referenced, which is the repaired state).
        $this->struktur = $this->makeWbsTask($this->graha, 'B', 'Pekerjaan Struktur');
        $this->pembesian = $this->makeWbsTask($this->graha, 'B.3', 'Pembesian besi beton ulir', $this->struktur, 5);

        $arsitektur = $this->makeWbsTask($this->graha, 'C', 'Pekerjaan Arsitektur & MEP');
        $this->bata = $this->makeWbsTask($this->graha, 'C.1', 'Pasangan bata, plesteran & finishing arsitektur', $arsitektur, 7);

        $bank = $this->makeProject('ELV & Data Center Bank Artha Nusantara', ['type' => 'system_integration']);
        $keamanan = $this->makeWbsTask($bank, 'B', 'Sistem Keamanan (CCTV & Akses Kontrol)');
        $this->cctv = $this->makeWbsTask($bank, 'B.1', 'Instalasi titik kamera CCTV dome indoor', $keamanan, 21);

        $this->actingAs($this->adminUser(), 'sanctum');
    }

    // ---------------------------------------------------------------- the id must belong here

    public function test_an_issue_cannot_name_a_wbs_task_of_another_project(): void
    {
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $this->cctv->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'wbs_task_id' => 'Tugas WBS yang dipilih bukan bagian dari WBS proyek ini.',
        ]);

        $this->assertDatabaseCount('inv_issues', 0);
    }

    public function test_an_issue_can_name_a_wbs_task_of_its_own_project(): void
    {
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $this->pembesian->id,
        ]));

        $response->assertCreated();

        $issue = Issue::query()->firstOrFail();

        $this->assertSame($this->pembesian->id, (int) $issue->wbs_task_id);
    }

    public function test_a_wbs_task_id_that_does_not_exist_is_refused(): void
    {
        // The value the old ['nullable', 'integer'] rule was happy with, and the
        // reason an unvalidated foreign key is worse than an empty one.
        $this->postJson('/api/inventory/issues', $this->payload(['wbs_task_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['wbs_task_id']);

        $this->assertDatabaseCount('inv_issues', 0);

        // Not a blanket refusal: a real leaf of this project still passes.
        $this->postJson('/api/inventory/issues', $this->payload(['wbs_task_id' => $this->bata->id]))
            ->assertCreated();

        $this->assertDatabaseCount('inv_issues', 1);
    }

    public function test_an_issue_cannot_be_charged_to_a_project_that_does_not_exist(): void
    {
        // project_id was ['nullable', 'integer'] with no existence check, and
        // it is the grouping key of the whole package: 999999 posted Rp
        // 9.300.000 of HPP into 5-1100 against a project no report can ever
        // find — invisible to EVM's AC, totalsByCategory and the PSAK 115 cost
        // base, where an honest no-project bon lands in 6-4100 overhead.
        $this->postJson('/api/inventory/issues', $this->payload(['project_id' => 999999, 'wbs_task_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        $this->assertDatabaseCount('inv_issues', 0);

        // A soft-deleted project is equally gone — same whereNull('deleted_at')
        // contract as GoodsReceiptStoreRequest::crossModuleId.
        $terminated = $this->makeProject('Proyek Dihentikan Kontraknya');
        $terminated->delete();

        $this->postJson('/api/inventory/issues', $this->payload(['project_id' => $terminated->id, 'wbs_task_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id']);

        // And the guard is a guard, not a blockade: the real project passes.
        $this->postJson('/api/inventory/issues', $this->payload())->assertCreated();

        $this->assertDatabaseCount('inv_issues', 1);
    }

    public function test_an_issue_line_cannot_name_a_wbs_task_of_another_project(): void
    {
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'items' => [
                ['item_id' => $this->semen->id, 'qty' => 150, 'wbs_task_id' => $this->bata->id],
                ['item_id' => $this->besi->id, 'qty' => 80, 'wbs_task_id' => $this->cctv->id],
            ],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'items.1.wbs_task_id' => 'Tugas WBS pada baris ini bukan bagian dari WBS proyek ini.',
        ]);

        // The good line does not rescue the bad one: nothing is written.
        $this->assertDatabaseCount('inv_issues', 0);
        $this->assertDatabaseCount('inv_issue_items', 0);
    }

    // ---------------------------------------------------------------- it must be a work package

    public function test_an_issue_cannot_name_a_parent_wbs_task(): void
    {
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $this->struktur->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'wbs_task_id' => 'Tugas WBS yang dipilih masih punya sub-tugas; pilih paket pekerjaan paling bawah.',
        ]);

        // Same rule on a line, so a bon cannot smuggle a section in per row.
        $this->postJson('/api/inventory/issues', $this->payload([
            'items' => [
                ['item_id' => $this->semen->id, 'qty' => 150, 'wbs_task_id' => $this->struktur->id],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'items.0.wbs_task_id' => 'Tugas WBS yang dipilih masih punya sub-tugas; pilih paket pekerjaan paling bawah.',
        ]);

        $this->assertDatabaseCount('inv_issues', 0);
    }

    public function test_an_issue_cannot_name_a_leaf_wbs_task_that_carries_no_boq_item(): void
    {
        // The live C.2 "MEP, ELV & ICT" exactly: childless, so the old
        // has-children proxy waved it through — but boq_item_id is null, so the
        // variance report joins to no BOQ row, computes no theory, and the
        // "attributed" line is dropped exactly like an untagged one. On
        // PRJ-2026-001 the leaves in this state are 14,67% of the project by
        // weight; a tag that cannot be computed is worse than no tag.
        $mep = $this->makeWbsTask($this->graha, 'C.2', 'MEP, ELV & ICT');

        $message = 'Tugas WBS yang dipilih tidak terhubung ke item BOQ, sehingga pemakaian '
            .'material tidak dapat dibandingkan dengan analisa harga satuan.';

        $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $mep->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['wbs_task_id' => $message]);

        // Same rule per line, so a bon cannot smuggle it in per row.
        $this->postJson('/api/inventory/issues', $this->payload([
            'items' => [
                ['item_id' => $this->semen->id, 'qty' => 150, 'wbs_task_id' => $mep->id],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors(['items.0.wbs_task_id' => $message]);

        $this->assertDatabaseCount('inv_issues', 0);
    }

    public function test_an_issue_can_name_a_leaf_wbs_task(): void
    {
        // B.3 sits directly under the section B that was just refused: the rule
        // refuses sections and BOQ-less leaves, not everything under a parent.
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $this->pembesian->id,
            'items' => [
                ['item_id' => $this->besi->id, 'qty' => 80, 'wbs_task_id' => $this->pembesian->id],
            ],
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('inv_issue_items', [
            'item_id' => $this->besi->id,
            'wbs_task_id' => $this->pembesian->id,
        ]);
    }

    // ---------------------------------------------------------------- no project, no work package

    public function test_a_wbs_task_cannot_be_named_on_an_issue_that_has_no_project(): void
    {
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'project_id' => null,
            'wbs_task_id' => $this->pembesian->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'wbs_task_id' => 'Tugas WBS yang dipilih bukan bagian dari WBS proyek ini.',
        ]);

        // The bon itself is still legitimate — material for the site office is
        // overhead and belongs to no project. Only the attribution is refused.
        $this->postJson('/api/inventory/issues', $this->payload(['project_id' => null]))
            ->assertCreated();

        $this->assertDatabaseCount('inv_issues', 1);
    }

    // ---------------------------------------------------------------- header defaults, line decides

    public function test_an_issue_line_inherits_the_header_wbs_task_when_it_names_none(): void
    {
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $this->pembesian->id,
            'items' => [
                ['item_id' => $this->semen->id, 'qty' => 150],
                ['item_id' => $this->besi->id, 'qty' => 80],
            ],
        ]));

        $response->assertCreated();

        // One field for the ordinary single-package bon: the storeman typed the
        // package once and both lines are attributed.
        $this->assertSame(
            [$this->pembesian->id, $this->pembesian->id],
            Issue::query()->firstOrFail()->items()->orderBy('id')->pluck('wbs_task_id')->map(intval(...))->all(),
        );

        // And the line grid can read it back.
        $this->assertSame(
            $this->pembesian->id,
            (int) $this->getJson('/api/inventory/issues/'.$response->json('data.id'))->json('data.items.0.wbs_task_id'),
        );
    }

    public function test_an_issue_line_may_override_the_header_wbs_task_so_one_bon_can_serve_two_work_packages(): void
    {
        // ISS/2026/VII/0001 exactly: cement for the masonry analysis, rebar for
        // the pembesian analysis, one document. A header-only attribution has to
        // be wrong about one of these two lines.
        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $this->pembesian->id,
            'items' => [
                ['item_id' => $this->semen->id, 'qty' => 150, 'wbs_task_id' => $this->bata->id],
                ['item_id' => $this->besi->id, 'qty' => 80],
            ],
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('inv_issue_items', [
            'item_id' => $this->semen->id,
            'wbs_task_id' => $this->bata->id, // C.1 — the line wins over the header
        ]);
        $this->assertDatabaseHas('inv_issue_items', [
            'item_id' => $this->besi->id,
            'wbs_task_id' => $this->pembesian->id, // B.3 — inherited
        ]);
    }

    public function test_editing_only_the_header_leaves_the_existing_line_attribution_alone(): void
    {
        $issueId = (int) $this->postJson('/api/inventory/issues', $this->payload([
            'wbs_task_id' => $this->pembesian->id,
            'items' => [['item_id' => $this->besi->id, 'qty' => 80]],
        ]))->json('data.id');

        // The header is only a default at write time. An update that sends no
        // lines does not re-tag them — stated here because "the line is the
        // authority" is otherwise indistinguishable from a bug.
        $this->putJson("/api/inventory/issues/{$issueId}", ['wbs_task_id' => $this->bata->id])
            ->assertOk();

        $this->assertDatabaseHas('inv_issues', ['id' => $issueId, 'wbs_task_id' => $this->bata->id]);
        $this->assertDatabaseHas('inv_issue_items', [
            'issue_id' => $issueId,
            'wbs_task_id' => $this->pembesian->id,
        ]);
    }

    // ---------------------------------------------------------------- the update path

    public function test_an_update_that_only_changes_the_wbs_task_is_checked_against_the_issues_own_project(): void
    {
        $issueId = (int) $this->postJson('/api/inventory/issues', $this->payload())->json('data.id');

        // No project_id in the payload. Reading it from the request alone would
        // check the task against a null project — whereNull('project_id'),
        // which matches nothing — and refuse every re-tag ever made.
        $this->putJson("/api/inventory/issues/{$issueId}", ['wbs_task_id' => $this->pembesian->id])
            ->assertOk();

        $this->assertDatabaseHas('inv_issues', ['id' => $issueId, 'wbs_task_id' => $this->pembesian->id]);

        // The fallback is a fallback, not an amnesty: another project's leaf is
        // still refused.
        $this->putJson("/api/inventory/issues/{$issueId}", ['wbs_task_id' => $this->cctv->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'wbs_task_id' => 'Tugas WBS yang dipilih bukan bagian dari WBS proyek ini.',
            ]);

        $this->assertDatabaseHas('inv_issues', ['id' => $issueId, 'wbs_task_id' => $this->pembesian->id]);
    }

    public function test_the_project_on_an_issue_cannot_be_moved_by_an_update(): void
    {
        $bank = Project::query()->where('name', 'like', 'ELV%')->firstOrFail();

        $issueId = (int) $this->postJson('/api/inventory/issues', $this->payload([
            'items' => [['item_id' => $this->besi->id, 'qty' => 80, 'wbs_task_id' => $this->pembesian->id]],
        ]))->json('data.id');

        // The two-request poisoning this pins shut: re-home the header, send no
        // items, and the stored lines keep the OLD project's WBS ids — a bank
        // bon carrying Graha's B.3, permanent the moment it posts. project_id
        // is simply not writable on an update (DefectUpdateRequest contract),
        // so the request succeeds and moves nothing.
        $this->putJson("/api/inventory/issues/{$issueId}", ['project_id' => $bank->id])
            ->assertOk();

        $this->assertDatabaseHas('inv_issues', ['id' => $issueId, 'project_id' => $this->graha->id]);
        $this->assertDatabaseHas('inv_issue_items', [
            'issue_id' => $issueId,
            'wbs_task_id' => $this->pembesian->id,
        ]);

        // Nulling it is the same move with the same answer — it used to defeat
        // the "no project => no WBS attribution" rule in one request.
        $this->putJson("/api/inventory/issues/{$issueId}", ['project_id' => null])->assertOk();

        $this->assertDatabaseHas('inv_issues', ['id' => $issueId, 'project_id' => $this->graha->id]);
    }

    public function test_a_payload_project_id_cannot_smuggle_another_projects_wbs_task_past_validation(): void
    {
        $issueId = (int) $this->postJson('/api/inventory/issues', $this->payload())->json('data.id');

        $bank = Project::query()->where('name', 'like', 'ELV%')->firstOrFail();

        // The side door once the front door is shut: name the other project AND
        // its leaf in one payload. The WBS check must run against the project
        // the issue KEEPS (Graha), not the one the payload wishes for.
        $this->putJson("/api/inventory/issues/{$issueId}", [
            'project_id' => $bank->id,
            'wbs_task_id' => $this->cctv->id,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'wbs_task_id' => 'Tugas WBS yang dipilih bukan bagian dari WBS proyek ini.',
        ]);

        $this->assertDatabaseHas('inv_issues', ['id' => $issueId, 'project_id' => $this->graha->id, 'wbs_task_id' => null]);

        // Still editable in the ways that are legitimate: a leaf of the stored
        // project passes even when the payload carries the ignored project_id.
        $this->putJson("/api/inventory/issues/{$issueId}", [
            'project_id' => $bank->id,
            'wbs_task_id' => $this->bata->id,
        ])->assertOk();

        $this->assertDatabaseHas('inv_issues', ['id' => $issueId, 'project_id' => $this->graha->id, 'wbs_task_id' => $this->bata->id]);
    }

    // ---------------------------------------------------------------- it has to survive posting

    public function test_a_posted_issue_keeps_the_work_package_on_every_line(): void
    {
        $this->seedLedger(2026);
        $this->receiveStock($this->gudang, $this->besi, 200, 118000, '2026-07-01');

        // Posting rewrites unit_cost and amount on every line; the attribution
        // must not be a casualty of that, because the variance report only ever
        // reads POSTED issues.
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->gudang, [[$this->besi, 80, $this->pembesian->id]], $this->graha->id, '2026-07-05')
        );

        $line = $issue->items()->firstOrFail();

        $this->assertSame($this->pembesian->id, (int) $line->wbs_task_id);
        $this->assertSame(118000.0, (float) $line->unit_cost);
        $this->assertSame(9440000.0, (float) $line->amount); // 80 btg * 118.000
    }

    // ---------------------------------------------------------------- and into the cost ledger

    public function test_posting_a_two_package_bon_writes_one_project_cost_row_per_line_each_carrying_its_work_package(): void
    {
        $this->seedLedger(2026);
        $this->receiveStock($this->gudang, $this->semen, 500, 62000, '2026-07-01');
        $this->receiveStock($this->gudang, $this->besi, 200, 118000, '2026-07-01');

        // ISS/2026/VII/0001 to the rupiah: cement for C.1, rebar for B.3, both
        // `material`, one bon. Recorded per ISSUE this was ONE row of Rp
        // 18.740.000 with a single wbs_task_id slot — whichever line recorded
        // last kept it (and on the shipped code, neither did: the eighth
        // argument was never passed and the slot stayed null).
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->gudang, [
                [$this->semen, 150, $this->bata->id],
                [$this->besi, 80, $this->pembesian->id],
            ], $this->graha->id, '2026-07-05')
        );

        $costs = ProjectCost::query()->orderBy('reference_id')->get();
        $lineIds = $issue->items()->orderBy('id')->pluck('id')->map(intval(...))->all();

        $this->assertCount(2, $costs);

        // 150 zak * 62.000 = 9.300.000 charged to the pasangan bata package.
        $this->assertSame('inventory_issue_item', $costs[0]->reference_type);
        $this->assertSame($lineIds[0], (int) $costs[0]->reference_id);
        $this->assertSame('material', $costs[0]->cost_category->value);
        $this->assertSame(9300000.0, (float) $costs[0]->amount);
        $this->assertSame($this->bata->id, (int) $costs[0]->wbs_task_id);

        // 80 btg * 118.000 = 9.440.000 charged to the pembesian package.
        $this->assertSame($lineIds[1], (int) $costs[1]->reference_id);
        $this->assertSame('material', $costs[1]->cost_category->value);
        $this->assertSame(9440000.0, (float) $costs[1]->amount);
        $this->assertSame($this->pembesian->id, (int) $costs[1]->wbs_task_id);

        // Splitting the row cannot change what the RAP is compared against:
        // 9.300.000 + 9.440.000 = the Rp 18.740.000 the single row carried.
        $this->assertSame(18740000.0, (float) ProjectCost::query()->sum('amount'));
    }

    public function test_a_posted_line_with_no_work_package_still_reaches_the_cost_ledger_unattributed(): void
    {
        $this->seedLedger(2026);
        $this->receiveStock($this->gudang, $this->semen, 500, 62000, '2026-07-01');

        // No WBS anywhere: realisasi per category must not depend on the
        // storeman filling the new field — the row lands with wbs_task_id
        // null, which is what the variance report's unattributed bucket reads.
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->gudang, [[$this->semen, 150]], $this->graha->id, '2026-07-05')
        );

        $cost = ProjectCost::query()->sole();

        $this->assertSame('inventory_issue_item', $cost->reference_type);
        $this->assertSame((int) $issue->items()->sole()->id, (int) $cost->reference_id);
        $this->assertSame(9300000.0, (float) $cost->amount); // 150 zak * 62.000
        $this->assertNull($cost->wbs_task_id);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->gudang->id,
            'project_id' => $this->graha->id,
            'issue_date' => '2026-07-05',
            'purpose' => 'Pengecoran kolom lantai 1 zona A — Gedung Graha Sentosa.',
            'items' => [
                ['item_id' => $this->semen->id, 'qty' => 150],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProject(string $name, array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'name' => $name,
            'type' => 'construction',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'contract_value' => 48500000000,
            'status' => ProjectStatus::Active,
        ], $attributes));
    }

    /**
     * Parents are sections and carry no BOQ item, exactly as
     * ProjectService::generateWbsFromBoq builds them; a WORK PACKAGE names the
     * est_boq_items row its theory hangs off. The column is a cross-module id
     * with no FK, so the fixture ids stand in for BOQ rows that need not exist.
     */
    private function makeWbsTask(Project $project, string $code, string $name, ?WbsTask $parent = null, ?int $boqItemId = null): WbsTask
    {
        return WbsTask::query()->create([
            'project_id' => $project->id,
            'parent_id' => $parent?->id,
            'boq_item_id' => $boqItemId,
            'wbs_code' => $code,
            'name' => $name,
            'weight_pct' => 0,
            'progress_pct' => 0,
        ]);
    }
}
