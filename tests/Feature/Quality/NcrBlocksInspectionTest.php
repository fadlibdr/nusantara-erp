<?php

namespace Tests\Feature\Quality;

use Modules\Core\Models\Location;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Models\Ncr;
use Tests\ErpTestCase;

/**
 * P1-QC — THE BLOCK. An OPEN NCR at a location refuses the submit of a
 * LATER-stage inspection at the SAME location (before < during < after). A
 * same-stage re-inspection passes; a different location passes; an earlier stage
 * passes; and once the NCR is verified, nothing blocks.
 */
class NcrBlocksInspectionTest extends ErpTestCase
{
    use QualityFixtures;

    private function draftInspection(Project $project, InspectionTemplate $template, Location $location): Inspection
    {
        $this->admin();

        $response = $this->postJson('api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-20',
            'results' => [],
        ]);

        $response->assertCreated();

        return Inspection::query()->findOrFail($response->json('data.id'));
    }

    private function openNcr(Project $project, Location $location, InspectionStage $stage): Ncr
    {
        $this->admin();

        $response = $this->postJson('api/quality/ncr', [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'stage' => $stage->value,
            'description' => 'Tulangan kolom kurang sesuai gambar',
            'responsible_employee_id' => $this->employee()->id,
        ]);

        $response->assertCreated();

        return Ncr::query()->findOrFail($response->json('data.id'));
    }

    public function test_a_later_stage_inspection_at_the_same_location_is_blocked_and_named(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $ncr = $this->openNcr($project, $location, InspectionStage::Before);

        $inspection = $this->draftInspection($project, $this->template(InspectionStage::After), $location);

        $this->admin();
        $response = $this->postJson("api/quality/inspections/{$inspection->id}/submit");

        $response->assertStatus(422);
        $this->assertStringContainsString($ncr->code, (string) $response->json('message'));
        $this->assertSame('draft', $inspection->fresh()->status->value);
    }

    public function test_a_same_stage_inspection_at_the_same_location_passes(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $this->openNcr($project, $location, InspectionStage::Before);

        $inspection = $this->draftInspection($project, $this->template(InspectionStage::Before), $location);

        $this->admin();
        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertOk();
        $this->assertSame('submitted', $inspection->fresh()->status->value);
    }

    public function test_a_later_stage_inspection_at_a_different_location_passes(): void
    {
        $project = $this->project();
        $l1 = $this->location($project, ['code' => 'BLK-A', 'name' => 'Zona A']);
        $l2 = $this->location($project, ['code' => 'BLK-B', 'name' => 'Zona B']);
        $this->openNcr($project, $l1, InspectionStage::Before);

        $inspection = $this->draftInspection($project, $this->template(InspectionStage::After), $l2);

        $this->admin();
        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertOk();
        $this->assertSame('submitted', $inspection->fresh()->status->value);
    }

    public function test_an_earlier_stage_inspection_is_not_blocked(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $this->openNcr($project, $location, InspectionStage::After);

        $inspection = $this->draftInspection($project, $this->template(InspectionStage::Before), $location);

        $this->admin();
        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertOk();
    }

    public function test_a_verified_ncr_no_longer_blocks_the_next_stage(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $ncr = $this->openNcr($project, $location, InspectionStage::Before);

        $inspection = $this->draftInspection($project, $this->template(InspectionStage::After), $location);

        // Blocked while open…
        $this->admin();
        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertStatus(422);

        // …verified by a second holder…
        $this->approver();
        $this->postJson("api/quality/ncr/{$ncr->id}/verify", ['verified_at' => '2026-03-22'])->assertOk();

        // …and the next stage now proceeds.
        $this->admin();
        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertOk();
        $this->assertSame('submitted', $inspection->fresh()->status->value);
    }
}
