<?php

namespace Tests\Feature\Engineering;

use Laravel\Sanctum\Sanctum;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P8 — revisi generik (D9) pada IPP. Baris baru bernomor baru, pendahulu
 * distempel dan mempertahankan riwayat persetujuannya; baris-baris material/
 * alat/gambar ikut tersalin ke revisi supaya sheet baru berangkat dari isi
 * yang direvisi, bukan dari nol.
 */
class IppRevisionTest extends ErpTestCase
{
    use EngineeringFixtures;

    private function ipp(Project $project): WorkPermitIpp
    {
        $this->admin();

        $response = $this->postJson('api/engineering/ipp', [
            'project_id' => $project->id,
            'scope' => 'struktur',
            'description' => 'Pengecoran pile cap zona A',
            'planned_start' => '2026-04-01',
            'duration_days' => 14,
            'materials' => [
                ['description' => 'Ready Mix K-300', 'qty' => 45, 'unit' => 'm3'],
            ],
            'equipment' => [
                ['description' => 'Concrete pump', 'qty' => 1],
            ],
        ]);

        $response->assertCreated();

        return WorkPermitIpp::query()->findOrFail($response->json('data.id'));
    }

    public function test_revising_an_ipp_copies_its_lines_into_a_new_draft(): void
    {
        $ipp = $this->ipp($this->project());
        $oldCode = $ipp->code;

        $revised = $this->postJson("api/engineering/ipp/{$ipp->id}/revise")
            ->assertCreated()->json('data');

        $successor = WorkPermitIpp::query()->findOrFail($revised['id']);

        $this->assertNotSame($ipp->id, $successor->id);
        $this->assertNotSame($oldCode, $successor->code);
        $this->assertSame('draft', $successor->status->value);
        $this->assertSame(1, (int) $successor->revision);

        // The lines came along — a revision starts from the content it revises.
        $this->assertSame(1, $successor->materials()->count());
        $this->assertSame('Ready Mix K-300', $successor->materials()->first()->description);
        $this->assertSame(1, $successor->equipment()->count());

        // The predecessor is stamped, keeps its number, keeps its own lines.
        $ipp->refresh();
        $this->assertSame($oldCode, $ipp->code);
        $this->assertNotNull($ipp->superseded_at);
        $this->assertSame($successor->id, $ipp->superseded_by_id);
        $this->assertSame(1, $ipp->materials()->count());
    }

    public function test_a_superseded_ipp_refuses_submit_and_approve(): void
    {
        $ipp = $this->ipp($this->project());
        $revised = $this->postJson("api/engineering/ipp/{$ipp->id}/revise")
            ->assertCreated()->json('data');

        $submit = $this->postJson("api/engineering/ipp/{$ipp->id}/submit");
        $submit->assertStatus(422);
        $this->assertStringContainsString('telah digantikan revisi', (string) $submit->json('message'));
        $this->assertStringContainsString($revised['code'], (string) $submit->json('message'));

        $this->postJson("api/engineering/ipp/{$ipp->id}/approve")->assertStatus(422);

        // A superseded draft also refuses edits — its content is history now.
        $update = $this->putJson("api/engineering/ipp/{$ipp->id}", ['description' => 'Diubah diam-diam']);
        $update->assertStatus(422);
        $this->assertStringContainsString('telah digantikan revisi', (string) $update->json('message'));

        $this->deleteJson("api/engineering/ipp/{$ipp->id}")->assertStatus(422);
    }

    public function test_an_approved_ipp_keeps_its_approval_history_after_revision(): void
    {
        $ipp = $this->ipp($this->project());

        $this->postJson("api/engineering/ipp/{$ipp->id}/submit")->assertOk();
        Sanctum::actingAs($this->recorder());
        $this->postJson("api/engineering/ipp/{$ipp->id}/approve")->assertOk();

        Sanctum::actingAs($this->admin());
        $before = $ipp->approvals()->pluck('action')->all();
        $this->assertContains('approved', $before);

        $this->postJson("api/engineering/ipp/{$ipp->id}/revise")->assertCreated();

        $ipp->refresh();
        $this->assertSame('approved', $ipp->status->value);
        $this->assertSame($before, $ipp->approvals()->pluck('action')->all());
    }
}
