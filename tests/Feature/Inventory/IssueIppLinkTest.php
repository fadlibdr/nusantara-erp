<?php

namespace Tests\Feature\Inventory;

use Modules\Core\Enums\DocumentStatus;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * P1-ENG × Inventory — the bon meets the Ijin Pelaksanaan Pekerjaan.
 *
 * Two rules, both in IssueService (never the controller):
 *
 * INHERITANCE. A bon that points at an IPP inherits the IPP's wbs_task_id as
 * its header default — the storeman names the permit he is drawing material
 * for, and the work-package attribution (the thing the material variance
 * report runs on) comes with it instead of being typed twice. The line-level
 * attribution rule is untouched: a line naming its own task still wins.
 *
 * THE CONFIRMATION, PriceDeviationService pattern — a WARNING, not a block: a
 * bon WITHOUT an IPP on a project that HAS active (approved) IPPs is 422 until
 * the payload carries confirm_without_ipp, and the message names the active
 * IPP numbers so what is being confirmed is a fact, not an empty sentence.
 * Material outside any permit is real (site consumables, cleanup) and must
 * stay possible — what is demanded is the explicit acknowledgement.
 */
class IssueIppLinkTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $gudang;

    private Item $semen;

    private Item $besi;

    private Project $graha;

    /** WBS "B.3" — a leaf carrying a BOQ item: the shape an IPP may name. */
    private WbsTask $pembesian;

    /** WBS "C.1" — a second leaf, for a line that names its own package. */
    private WbsTask $bata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = $this->makeWarehouse('WH-PRJ-2026-001');
        $this->semen = $this->makeItem('Semen Portland 50kg');
        $this->besi = $this->makeItem('Besi Beton D16', ['unit' => 'btg']);

        $this->graha = $this->makeProject('Pembangunan Gedung Kantor Graha Sentosa');

        $struktur = $this->makeWbsTask($this->graha, 'B', 'Pekerjaan Struktur');
        $this->pembesian = $this->makeWbsTask($this->graha, 'B.3', 'Pembesian besi beton ulir', $struktur, 5);

        $arsitektur = $this->makeWbsTask($this->graha, 'C', 'Pekerjaan Arsitektur & MEP');
        $this->bata = $this->makeWbsTask($this->graha, 'C.1', 'Pasangan bata & plesteran', $arsitektur, 7);

        $this->actingAs($this->adminUser(), 'sanctum');
    }

    // ------------------------------------------------------------ inheritance

    public function test_a_bon_pointing_at_an_ipp_inherits_its_wbs_task(): void
    {
        $ipp = $this->approvedIpp($this->graha, $this->pembesian->id);

        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'ipp_id' => $ipp->id,
        ]));

        $response->assertCreated();

        $issue = Issue::query()->firstOrFail();
        $this->assertSame($ipp->id, (int) $issue->ipp_id);
        // Header inherited from the IPP…
        $this->assertSame($this->pembesian->id, (int) $issue->wbs_task_id);
        // …and copied down to the line by the existing attribution default.
        $this->assertSame($this->pembesian->id, (int) $issue->items()->firstOrFail()->wbs_task_id);
    }

    public function test_a_line_naming_its_own_task_still_wins_over_the_inherited_header(): void
    {
        $ipp = $this->approvedIpp($this->graha, $this->pembesian->id);

        $this->postJson('/api/inventory/issues', $this->payload([
            'ipp_id' => $ipp->id,
            'items' => [
                ['item_id' => $this->besi->id, 'qty' => 80],
                ['item_id' => $this->semen->id, 'qty' => 150, 'wbs_task_id' => $this->bata->id],
            ],
        ]))->assertCreated();

        $issue = Issue::query()->firstOrFail();
        $lines = $issue->items()->orderBy('id')->get();

        $this->assertSame($this->pembesian->id, (int) $lines[0]->wbs_task_id); // inherited default
        $this->assertSame($this->bata->id, (int) $lines[1]->wbs_task_id); // the line is the authority
    }

    public function test_a_header_task_conflicting_with_the_ipp_is_refused(): void
    {
        $ipp = $this->approvedIpp($this->graha, $this->pembesian->id);

        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'ipp_id' => $ipp->id,
            'wbs_task_id' => $this->bata->id,
        ]));

        // Silently keeping either value would make the sheet lie about one of
        // the two: the permit names the work, the header claims different work.
        $response->assertStatus(422)->assertJsonValidationErrors(['wbs_task_id']);
        $message = implode(' ', $response->json('errors.wbs_task_id'));
        $this->assertStringContainsString($ipp->code, $message);
        $this->assertStringContainsString('B.3', $message);
        $this->assertStringContainsString('C.1', $message);

        $this->assertDatabaseCount('inv_issues', 0);
    }

    // ------------------------------------------------------- the IPP must fit

    public function test_a_bon_cannot_point_at_an_ipp_of_another_project(): void
    {
        $bank = $this->makeProject('ELV & Data Center Bank Artha', ['type' => 'system_integration']);
        $ipp = $this->approvedIpp($bank);

        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'ipp_id' => $ipp->id,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['ipp_id']);
        $this->assertStringContainsString($ipp->code, implode(' ', $response->json('errors.ipp_id')));
        $this->assertDatabaseCount('inv_issues', 0);
    }

    public function test_a_bon_cannot_point_at_an_ipp_that_is_not_yet_approved(): void
    {
        $ipp = $this->approvedIpp($this->graha, $this->pembesian->id, approved: false); // still draft

        $response = $this->postJson('/api/inventory/issues', $this->payload([
            'ipp_id' => $ipp->id,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['ipp_id']);
        $message = implode(' ', $response->json('errors.ipp_id'));
        $this->assertStringContainsString($ipp->code, $message);
        $this->assertStringContainsString('Draf', $message);
        $this->assertDatabaseCount('inv_issues', 0);
    }

    // ------------------------------------------------ the confirmation escape

    public function test_a_bon_without_ipp_on_a_project_with_active_ipp_requires_confirmation(): void
    {
        $first = $this->approvedIpp($this->graha, $this->pembesian->id);
        $second = $this->approvedIpp($this->graha, null, attributes: [
            'description' => 'Pasangan bata lantai 2 zona B',
        ]);

        $response = $this->postJson('/api/inventory/issues', $this->payload());

        // A warning, not a block — and it names EVERY active permit, because
        // "pilih IPP-nya" is only actionable when you can see the choices.
        $response->assertStatus(422)->assertJsonValidationErrors(['ipp_id']);
        $message = implode(' ', $response->json('errors.ipp_id'));
        $this->assertStringContainsString($first->code, $message);
        $this->assertStringContainsString($second->code, $message);
        $this->assertStringContainsString('konfirmasi', $message);
        $this->assertDatabaseCount('inv_issues', 0);

        // The escape hatch: same payload, explicit acknowledgement.
        $this->postJson('/api/inventory/issues', $this->payload([
            'confirm_without_ipp' => true,
        ]))->assertCreated();

        $issue = Issue::query()->firstOrFail();
        $this->assertNull($issue->ipp_id);
    }

    public function test_no_confirmation_is_demanded_when_the_project_has_no_active_ipp(): void
    {
        // A draft IPP authorises nothing yet, so it demands nothing.
        $this->approvedIpp($this->graha, approved: false);

        $this->postJson('/api/inventory/issues', $this->payload())->assertCreated();

        $this->assertDatabaseCount('inv_issues', 1);
    }

    public function test_an_office_bon_needs_no_confirmation_even_while_active_ipps_exist(): void
    {
        $this->approvedIpp($this->graha);

        // No project, no permit regime — the same shape FieldReportService
        // raises for service parts, which must never trip this gate.
        $this->postJson('/api/inventory/issues', $this->payload([
            'project_id' => null,
        ]))->assertCreated();

        $this->assertDatabaseCount('inv_issues', 1);
    }

    // ---------------------------------------------------------------- helpers

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->gudang->id,
            'project_id' => $this->graha->id,
            'issue_date' => '2026-07-05',
            'purpose' => 'Pembesian pile cap zona A — Gedung Graha Sentosa.',
            'items' => [
                ['item_id' => $this->besi->id, 'qty' => 80],
            ],
        ], $overrides);
    }

    /**
     * Assembled directly, seeder-style: maker-checker needs two people and a
     * fixture is nobody. The runtime submit → approve path (and the submittal
     * gate) is covered by tests/Feature/Engineering/IppGateTest.
     */
    private function approvedIpp(
        Project $project,
        ?int $wbsTaskId = null,
        bool $approved = true,
        array $attributes = [],
    ): WorkPermitIpp {
        $ipp = WorkPermitIpp::query()->create(array_merge([
            'project_id' => $project->id,
            'scope' => 'struktur',
            'description' => 'Pengecoran pile cap zona A',
            'planned_start' => '2026-07-01',
            'duration_days' => 14,
            'wbs_task_id' => $wbsTaskId,
            'status' => DocumentStatus::Draft,
        ], $attributes));

        if ($approved) {
            $ipp->forceFill(['status' => DocumentStatus::Approved])->save();
        }

        return $ipp;
    }

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
