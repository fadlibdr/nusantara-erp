<?php

namespace Tests\Feature\Quality;

use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Models\Inspection;
use Tests\ErpTestCase;

/**
 * P8 — revisi generik (D9) pada inspeksi mutu. Baris baru bernomor QCI baru;
 * butir hasil tersalin sebagai titik berangkat revisi; pendahulu distempel,
 * mempertahankan nomor, status, dan riwayat persetujuannya.
 */
class InspectionRevisionTest extends ErpTestCase
{
    use QualityFixtures;

    private function inspection(): Inspection
    {
        $this->admin();

        $project = $this->project();
        $template = $this->template(InspectionStage::Before);
        [$a, $b] = $template->items()->orderBy('sort_order')->pluck('id')->all();

        $response = $this->postJson('api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'witness_party' => 'mk',
            'results' => [
                ['template_item_id' => $a, 'result' => 'ok'],
                ['template_item_id' => $b, 'result' => 'nok', 'remark' => 'Bekisting belum siku'],
            ],
        ]);

        $response->assertCreated();

        return Inspection::query()->findOrFail($response->json('data.id'));
    }

    public function test_revising_an_inspection_copies_the_result_rows(): void
    {
        $inspection = $this->inspection();
        $oldCode = $inspection->code;

        $revised = $this->postJson("api/quality/inspections/{$inspection->id}/revise")
            ->assertCreated()->json('data');

        $successor = Inspection::query()->findOrFail($revised['id']);

        $this->assertNotSame($oldCode, $successor->code);
        $this->assertSame('draft', $successor->status->value);
        $this->assertSame(1, (int) $successor->revision);

        // The ticked butir came along — including the derived verdict.
        $this->assertSame(2, $successor->results()->count());
        $this->assertFalse($successor->passed);

        $inspection->refresh();
        $this->assertSame($oldCode, $inspection->code);
        $this->assertNotNull($inspection->superseded_at);
        $this->assertSame($successor->id, $inspection->superseded_by_id);
        $this->assertSame(2, $inspection->results()->count());
    }

    public function test_a_superseded_inspection_refuses_the_live_rows_actions(): void
    {
        $inspection = $this->inspection();
        $revised = $this->postJson("api/quality/inspections/{$inspection->id}/revise")
            ->assertCreated()->json('data');

        $submit = $this->postJson("api/quality/inspections/{$inspection->id}/submit");
        $submit->assertStatus(422);
        $this->assertStringContainsString('telah digantikan revisi', (string) $submit->json('message'));
        $this->assertStringContainsString($revised['code'], (string) $submit->json('message'));

        $this->postJson("api/quality/inspections/{$inspection->id}/approve")->assertStatus(422);

        $update = $this->putJson("api/quality/inspections/{$inspection->id}", [
            'location_id' => $inspection->location_id,
            'inspected_at' => '2026-03-17',
        ]);
        $update->assertStatus(422);
        $this->assertStringContainsString('telah digantikan revisi', (string) $update->json('message'));

        $this->deleteJson("api/quality/inspections/{$inspection->id}")->assertStatus(422);
    }

    public function test_an_approved_inspection_keeps_its_approval_history_after_revision(): void
    {
        $inspection = $this->inspection();

        $this->postJson("api/quality/inspections/{$inspection->id}/submit")->assertOk();
        $this->approver();
        $this->postJson("api/quality/inspections/{$inspection->id}/approve")->assertOk();

        $this->admin();
        $before = $inspection->approvals()->pluck('action')->all();
        $this->assertContains('approved', $before);

        $this->postJson("api/quality/inspections/{$inspection->id}/revise")->assertCreated();

        $inspection->refresh();
        $this->assertSame('approved', $inspection->status->value);
        $this->assertSame($before, $inspection->approvals()->pluck('action')->all());
    }
}
