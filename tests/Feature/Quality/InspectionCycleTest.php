<?php

namespace Tests\Feature\Quality;

use Illuminate\Validation\ValidationException;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Services\InspectionTemplateService;
use Tests\ErpTestCase;

/**
 * P1-QC — the inspection lifecycle: a QCI number, an overall verdict DERIVED
 * from the ticked butir (never typed), and the house submit → approve
 * maker-checker.
 */
class InspectionCycleTest extends ErpTestCase
{
    use QualityFixtures;

    private function createInspection(Project $project, InspectionTemplate $template, int $locationId, array $results): Inspection
    {
        $this->admin();

        $response = $this->postJson('api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $locationId,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'witness_party' => 'mk',
            'results' => $results,
        ]);

        $response->assertCreated();

        return Inspection::query()->findOrFail($response->json('data.id'));
    }

    /** template item ids in order. */
    private function items(InspectionTemplate $template): array
    {
        return $template->items()->orderBy('sort_order')->pluck('id')->all();
    }

    public function test_an_inspection_gets_a_qci_number_and_starts_draft(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        [$a, $b] = $this->items($template);

        $inspection = $this->createInspection($project, $template, $this->location($project)->id, [
            ['template_item_id' => $a, 'result' => 'ok'],
            ['template_item_id' => $b, 'result' => 'ok'],
        ]);

        $this->assertMatchesRegularExpression('#^QCI/\d{4}/[IVX]+/\d{4}$#', $inspection->code);
        $this->assertSame('draft', $inspection->status->value);
        $this->assertSame(2, $inspection->results()->count());
    }

    public function test_overall_pass_is_derived_from_the_result_rows(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        [$a, $b] = $this->items($template);

        $allOk = $this->createInspection($project, $template, $this->location($project)->id, [
            ['template_item_id' => $a, 'result' => 'ok'],
            ['template_item_id' => $b, 'result' => 'na'], // na never fails the sheet
        ]);
        $this->assertTrue((bool) $allOk->passed);

        $oneNok = $this->createInspection($project, $template, $this->location($project)->id, [
            ['template_item_id' => $a, 'result' => 'ok'],
            ['template_item_id' => $b, 'result' => 'nok'],
        ]);
        $this->assertFalse((bool) $oneNok->passed);
    }

    /** `passed` is derived; a request that tries to force it is ignored. */
    public function test_passed_cannot_be_forced_from_the_request(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        [$a, $b] = $this->items($template);
        $this->admin();

        $response = $this->postJson('api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'passed' => true, // a lie: one line is nok
            'results' => [
                ['template_item_id' => $a, 'result' => 'nok'],
                ['template_item_id' => $b, 'result' => 'ok'],
            ],
        ]);

        $response->assertCreated();
        $this->assertFalse((bool) Inspection::query()->findOrFail($response->json('data.id'))->passed);
    }

    public function test_a_result_item_from_another_template_is_refused(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        $other = $this->template(InspectionStage::During);
        [$foreign] = $this->items($other);
        $this->admin();

        $this->postJson('api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'results' => [['template_item_id' => $foreign, 'result' => 'ok']],
        ])->assertStatus(422)->assertJsonValidationErrors('results.0.template_item_id');
    }

    public function test_a_location_from_another_project_is_refused(): void
    {
        $project = $this->project();
        $otherProject = $this->project(['code' => 'PRJ-2026-092']);
        $template = $this->template(InspectionStage::Before);
        $foreignLocation = $this->location($otherProject);
        $this->admin();

        $this->postJson('api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $foreignLocation->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'results' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_submit_then_approve_runs_the_house_maker_checker(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        [$a, $b] = $this->items($template);
        $inspection = $this->createInspection($project, $template, $this->location($project)->id, [
            ['template_item_id' => $a, 'result' => 'ok'],
            ['template_item_id' => $b, 'result' => 'ok'],
        ]);

        // admin (the creator/submitter) submits…
        $this->admin();
        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertOk();
        $this->assertSame('submitted', $inspection->fresh()->status->value);

        // …and cannot approve their own submission.
        $this->expectException(SelfApprovalException::class);
        $inspection->fresh()->approve($this->admin());
    }

    public function test_a_second_holder_approves_the_submitted_inspection(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        [$a, $b] = $this->items($template);
        $inspection = $this->createInspection($project, $template, $this->location($project)->id, [
            ['template_item_id' => $a, 'result' => 'ok'],
            ['template_item_id' => $b, 'result' => 'ok'],
        ]);

        $this->admin();
        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertOk();

        $this->approver();
        $this->postJson("api/quality/inspections/{$inspection->id}/approve")->assertOk();

        $this->assertSame('approved', $inspection->fresh()->status->value);
    }

    /**
     * Butir template yang sudah dipakai baris hasil inspeksi tidak bisa ditulis
     * ulang — 422 jujur, bukan 500 dari constraint FK.
     */
    public function test_a_template_whose_items_are_in_use_refuses_item_replacement(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        [$a, $b] = $this->items($template);

        // Isi satu inspeksi yang merujuk butir template ini.
        $this->createInspection($project, $template, $this->location($project)->id, [
            ['template_item_id' => $a, 'result' => 'ok'],
            ['template_item_id' => $b, 'result' => 'ok'],
        ]);

        $this->expectException(ValidationException::class);
        app(InspectionTemplateService::class)->replaceItems($template, [
            ['check_text' => 'Butir baru', 'acceptance' => 'OK'],
        ]);
    }
}
