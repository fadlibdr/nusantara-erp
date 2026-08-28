<?php

namespace Tests\Feature\Quality;

use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Enums\NcrStatus;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\Ncr;
use Tests\ErpTestCase;

/**
 * P1-QC — the NCR is not Approvable: its NcrStatus moves through explicit
 * transitions. Its responsible party is an XOR (one employee OR one
 * subcontractor), and its stage is inherited from the inspection it cites so the
 * two can never disagree.
 */
class NcrLifecycleTest extends ErpTestCase
{
    use QualityFixtures;

    private function inspection(Project $project, InspectionStage $stage): Inspection
    {
        $template = $this->template($stage);
        $this->admin();

        $response = $this->postJson('api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'results' => [],
        ]);

        return Inspection::query()->findOrFail($response->json('data.id'));
    }

    public function test_an_ncr_gets_a_number_and_starts_open(): void
    {
        $project = $this->project();
        $this->admin();

        $response = $this->postJson('api/quality/ncr', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'stage' => 'during',
            'description' => 'Keropos pada balok B3',
            'responsible_employee_id' => $this->employee()->id,
        ]);

        $response->assertCreated();
        $ncr = Ncr::query()->findOrFail($response->json('data.id'));

        $this->assertMatchesRegularExpression('#^NCR/\d{4}/[IVX]+/\d{4}$#', $ncr->code);
        $this->assertSame('open', $ncr->status->value);
    }

    public function test_the_stage_and_location_are_inherited_from_the_referenced_inspection(): void
    {
        $project = $this->project();
        $inspection = $this->inspection($project, InspectionStage::During);
        $this->admin();

        // No stage, no location supplied — both come off the inspection.
        $response = $this->postJson('api/quality/ncr', [
            'project_id' => $project->id,
            'inspection_id' => $inspection->id,
            'description' => 'Cacat ditemukan saat inspeksi',
            'subcontract_id' => $this->subcontract()->id,
        ]);

        $response->assertCreated();
        $ncr = Ncr::query()->findOrFail($response->json('data.id'));

        $this->assertSame(InspectionStage::During, $ncr->stage);
        $this->assertSame((int) $inspection->location_id, (int) $ncr->location_id);
    }

    public function test_a_standalone_ncr_without_a_stage_is_refused(): void
    {
        $project = $this->project();
        $this->admin();

        $this->postJson('api/quality/ncr', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'description' => 'NCR tanpa inspeksi dan tanpa tahap',
            'responsible_employee_id' => $this->employee()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('stage');
    }

    /** DoD: the responsible party is exactly one — both, or neither, is refused. */
    public function test_the_responsible_party_is_an_xor(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $employee = $this->employee();
        $subcontract = $this->subcontract();

        $base = [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'stage' => 'before',
            'description' => 'Ketidaksesuaian',
        ];

        $this->admin();

        // Both — refused.
        $this->postJson('api/quality/ncr', $base + [
            'responsible_employee_id' => $employee->id,
            'subcontract_id' => $subcontract->id,
        ])->assertStatus(422)->assertJsonValidationErrors('responsible_employee_id');

        // Neither — refused.
        $this->postJson('api/quality/ncr', $base)
            ->assertStatus(422)->assertJsonValidationErrors('responsible_employee_id');

        // Employee only — accepted.
        $this->postJson('api/quality/ncr', $base + ['responsible_employee_id' => $employee->id])
            ->assertCreated();

        // Subcontractor only — accepted.
        $this->postJson('api/quality/ncr', $base + ['subcontract_id' => $subcontract->id])
            ->assertCreated();
    }

    public function test_the_status_transitions_open_to_closed(): void
    {
        $project = $this->project();
        $this->admin();
        $ncr = Ncr::query()->findOrFail($this->postJson('api/quality/ncr', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'stage' => 'before',
            'description' => 'Ketidaksesuaian yang akan diperbaiki',
            'responsible_employee_id' => $this->employee()->id,
        ])->json('data.id'));

        $this->postJson("api/quality/ncr/{$ncr->id}/start-correction")->assertOk();
        $this->assertSame('under_correction', $ncr->fresh()->status->value);

        $this->approver();
        $this->postJson("api/quality/ncr/{$ncr->id}/verify", ['verified_at' => '2026-04-01'])->assertOk();
        $verified = $ncr->fresh();
        $this->assertSame('verified', $verified->status->value);
        $this->assertNotNull($verified->verified_by);
        $this->assertSame('2026-04-01', $verified->verified_at->toDateString());

        $this->admin();
        $this->postJson("api/quality/ncr/{$ncr->id}/close")->assertOk();
        $this->assertSame('closed', $ncr->fresh()->status->value);
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $project = $this->project();
        $this->admin();
        $ncr = Ncr::query()->findOrFail($this->postJson('api/quality/ncr', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'stage' => 'before',
            'description' => 'Ketidaksesuaian',
            'responsible_employee_id' => $this->employee()->id,
        ])->json('data.id'));

        // Cannot close an NCR that has not been verified.
        $this->postJson("api/quality/ncr/{$ncr->id}/close")->assertStatus(422);
        $this->assertSame('open', $ncr->fresh()->status->value);
    }

    /**
     * The two readers of "open" — NcrStatus::isOpen() (Quality) and the literal
     * strings BastPrerequisiteService uses (Projects) — must not drift.
     */
    public function test_open_values_match_the_is_open_predicate(): void
    {
        $expected = array_values(array_map(
            fn (NcrStatus $s): string => $s->value,
            array_filter(NcrStatus::cases(), fn (NcrStatus $s): bool => $s->isOpen()),
        ));

        $this->assertSame($expected, NcrStatus::openValues());
        $this->assertSame(['open', 'under_correction'], NcrStatus::openValues());
    }
}
