<?php

namespace Tests\Feature\Engineering;

use Modules\Core\Models\Notification;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Tests\ErpTestCase;

/**
 * P1-ENG — the backbone the deviation report says is missing: drawing →
 * material → IPP. An Ijin Pelaksanaan Pekerjaan CANNOT be submitted while any
 * drawing line rides a submittal the MK has not stamped approved /
 * approved-as-noted, or any material line rides an unapproved material
 * submittal — and the 422 names the blocking document numbers, because the
 * site engineer's next act is to chase exactly those sheets.
 */
class IppGateTest extends ErpTestCase
{
    use EngineeringFixtures;

    private function ipp(Project $project, array $lines = []): WorkPermitIpp
    {
        $this->admin();

        $response = $this->postJson('api/engineering/ipp', array_merge([
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
        ], $lines));

        $response->assertCreated();

        return WorkPermitIpp::query()->findOrFail($response->json('data.id'));
    }

    public function test_an_ipp_gets_an_ipp_number_and_starts_as_draft(): void
    {
        $ipp = $this->ipp($this->project());

        $this->assertMatchesRegularExpression('#^IPP/\d{4}/[IVX]+/\d{4}$#', $ipp->code);
        $this->assertSame('draft', $ipp->status->value);
        $this->assertSame(1, $ipp->materials()->count());
        $this->assertSame(1, $ipp->equipment()->count());
    }

    public function test_submit_is_refused_while_a_drawing_line_is_undecided_and_the_message_names_it(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project)); // no decision yet

        $ipp = $this->ipp($project, ['drawings' => [['drawing_submittal_id' => $submittal->id]]]);

        $this->admin();
        $response = $this->postJson("api/engineering/ipp/{$ipp->id}/submit");

        $response->assertStatus(422);
        $this->assertStringContainsString($submittal->code, (string) $response->json('message'));
        $this->assertStringContainsString('menunggu keputusan', (string) $response->json('message'));
        $this->assertSame('draft', $ipp->fresh()->status->value);
    }

    public function test_submit_is_refused_when_a_drawing_line_was_stamped_revise_resubmit(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project));

        $this->recorder();
        $this->postJson("api/engineering/drawing-submittals/{$submittal->id}/decision", [
            'decision' => 'revise_resubmit',
            'decided_at' => '2026-03-12',
        ])->assertOk();

        $ipp = $this->ipp($project, ['drawings' => [['drawing_submittal_id' => $submittal->id]]]);

        $this->admin();
        $response = $this->postJson("api/engineering/ipp/{$ipp->id}/submit");

        $response->assertStatus(422);
        $this->assertStringContainsString($submittal->code, (string) $response->json('message'));
        $this->assertStringContainsString('Revisi & ajukan ulang', (string) $response->json('message'));
    }

    public function test_submit_is_refused_when_the_referenced_submittal_has_been_superseded(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);
        $old = $this->submittal($drawing, ['revision' => 'R0']);

        $this->recorder();
        $this->postJson("api/engineering/drawing-submittals/{$old->id}/decision", [
            'decision' => 'approved',
            'decided_at' => '2026-03-10',
        ])->assertOk();

        // A newer revision arrives; the old approval no longer authorises work.
        $new = $this->submittal($drawing, ['revision' => 'R1', 'submitted_at' => '2026-03-20']);

        $ipp = $this->ipp($project, ['drawings' => [['drawing_submittal_id' => $old->id]]]);

        $this->admin();
        $response = $this->postJson("api/engineering/ipp/{$ipp->id}/submit");

        $response->assertStatus(422);
        $this->assertStringContainsString($old->code, (string) $response->json('message'));
        $this->assertStringContainsString($new->code, (string) $response->json('message'));
    }

    public function test_submit_is_refused_while_a_material_line_is_unapproved(): void
    {
        $project = $this->project();
        $material = $this->materialSubmittal($project); // undecided

        $ipp = $this->ipp($project, ['material_approvals' => [['material_submittal_id' => $material->id]]]);

        $this->admin();
        $response = $this->postJson("api/engineering/ipp/{$ipp->id}/submit");

        $response->assertStatus(422);
        $this->assertStringContainsString($material->code, (string) $response->json('message'));
    }

    public function test_approved_and_approved_as_noted_both_open_the_gate(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project));
        $material = $this->materialSubmittal($project);

        $this->recorder();
        $this->postJson("api/engineering/drawing-submittals/{$submittal->id}/decision", [
            'decision' => 'approved_as_noted',
            'decided_at' => '2026-03-12',
        ])->assertOk();
        $this->postJson("api/engineering/material-submittals/{$material->id}/decision", [
            'decision' => 'approved',
            'decided_at' => '2026-03-12',
        ])->assertOk();

        $ipp = $this->ipp($project, [
            'drawings' => [['drawing_submittal_id' => $submittal->id]],
            'material_approvals' => [['material_submittal_id' => $material->id]],
        ]);

        $this->admin();
        $this->postJson("api/engineering/ipp/{$ipp->id}/submit")->assertOk();
        $this->assertSame('submitted', $ipp->fresh()->status->value);
    }

    public function test_the_full_cycle_holds_maker_checker_and_notifies_eng_approvers(): void
    {
        $project = $this->project();
        $ipp = $this->ipp($project); // no submittal lines: gate has nothing to block

        $recorder = $this->recorder(); // holds eng.approve — should be notified

        $this->admin();
        $this->postJson("api/engineering/ipp/{$ipp->id}/submit")->assertOk();

        // ApprovableDocuments registration is what routes this notification.
        $this->assertSame(1, Notification::query()->where('user_id', $recorder->id)->count());

        // The submitter may not approve their own IPP (maker-checker).
        $selfApproval = $this->postJson("api/engineering/ipp/{$ipp->id}/approve");
        $selfApproval->assertStatus(422);

        // A different eng.approve holder may.
        $this->recorder();
        $this->postJson("api/engineering/ipp/{$ipp->id}/approve", ['note' => 'Lokasi siap.'])->assertOk();
        $this->assertSame('approved', $ipp->fresh()->status->value);
    }

    /**
     * THE ASYMMETRY, verbatim from the P1-ENG spec: a drawing line passes on
     * approved OR approved-as-noted, a material line ONLY on approved. The
     * stamp texts explain why the spec drew the line there: you can build from
     * a drawing while incorporating notes, but a material with notes ("ganti
     * merk", "lengkapi sertifikat") is not yet the material that may be
     * installed — the notes change WHAT arrives on site, not how it is read.
     */
    public function test_a_material_line_stamped_approved_as_noted_still_blocks_submit(): void
    {
        $project = $this->project();
        $material = $this->materialSubmittal($project);

        $this->recorder();
        $this->postJson("api/engineering/material-submittals/{$material->id}/decision", [
            'decision' => 'approved_as_noted',
            'decided_at' => '2026-03-12',
        ])->assertOk();

        $ipp = $this->ipp($project, ['material_approvals' => [['material_submittal_id' => $material->id]]]);

        $this->admin();
        $response = $this->postJson("api/engineering/ipp/{$ipp->id}/submit");

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringContainsString($material->code, $message);
        $this->assertStringContainsString('Disetujui dengan catatan', $message);
        $this->assertStringContainsString('Disetujui penuh', $message);
        $this->assertSame('draft', $ipp->fresh()->status->value);
    }

    public function test_the_gate_names_every_blocker_at_once(): void
    {
        $project = $this->project();

        $waiting = $this->submittal($this->drawing($project)); // no decision yet
        $revise = $this->submittal(
            $this->drawing($project, ['number' => 'GPC-ST-102', 'title' => 'Detail Pile Cap Tipe PC-2']),
        );
        $noted = $this->materialSubmittal($project);

        $this->recorder();
        $this->postJson("api/engineering/drawing-submittals/{$revise->id}/decision", [
            'decision' => 'revise_resubmit',
            'decided_at' => '2026-03-12',
        ])->assertOk();
        $this->postJson("api/engineering/material-submittals/{$noted->id}/decision", [
            'decision' => 'approved_as_noted',
            'decided_at' => '2026-03-12',
        ])->assertOk();

        $ipp = $this->ipp($project, [
            'drawings' => [
                ['drawing_submittal_id' => $waiting->id],
                ['drawing_submittal_id' => $revise->id],
            ],
            'material_approvals' => [['material_submittal_id' => $noted->id]],
        ]);

        $this->admin();
        $response = $this->postJson("api/engineering/ipp/{$ipp->id}/submit");

        // One refusal, all three sheets — a gate that reveals one blocker per
        // attempt teaches people to stop reading it.
        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringContainsString($waiting->code, $message);
        $this->assertStringContainsString($revise->code, $message);
        $this->assertStringContainsString($noted->code, $message);
        $this->assertSame('draft', $ipp->fresh()->status->value);
    }

    // ------------------------------------------------------- the IPP's WBS task

    /**
     * The IPP's wbs_task_id exists for ONE consumer: a bon that points at the
     * IPP inherits it (IssueService), and from there it feeds the material
     * variance report. So the value must satisfy exactly the rule the bon's
     * own picker enforces — a LEAF of THIS project CARRYING a BOQ item — or
     * inheritance would launder an attribution the request would have refused
     * if typed by hand. Same three sentences as IssueStoreRequest, on purpose.
     */
    public function test_an_ipp_wbs_task_must_be_a_work_package_of_its_own_project(): void
    {
        $project = $this->project();
        $parent = $this->wbsTask($project, 'B', 'Pekerjaan Struktur');
        $leaf = $this->wbsTask($project, 'B.3', 'Pembesian besi beton ulir', $parent->id, 5);
        $bare = $this->wbsTask($project, 'C.2', 'MEP, ELV & ICT'); // leaf, no BOQ item

        $other = $this->project(['code' => 'PRJ-2026-093', 'name' => 'Proyek Seberang']);
        $foreign = $this->wbsTask($other, 'B.1', 'Instalasi CCTV', null, 21);

        $this->admin();

        $this->postJson('api/engineering/ipp', $this->wbsPayload($project, $foreign->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['wbs_task_id']);

        $this->postJson('api/engineering/ipp', $this->wbsPayload($project, $parent->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'wbs_task_id' => 'Tugas WBS yang dipilih masih punya sub-tugas; pilih paket pekerjaan paling bawah.',
            ]);

        $this->postJson('api/engineering/ipp', $this->wbsPayload($project, $bare->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'wbs_task_id' => 'Tugas WBS yang dipilih tidak terhubung ke item BOQ, sehingga pemakaian material tidak dapat dibandingkan dengan analisa harga satuan.',
            ]);

        $response = $this->postJson('api/engineering/ipp', $this->wbsPayload($project, $leaf->id));
        $response->assertCreated();

        $ipp = WorkPermitIpp::query()->findOrFail($response->json('data.id'));
        $this->assertSame($leaf->id, (int) $ipp->wbs_task_id);
        $this->assertSame($leaf->id, (int) $response->json('data.wbs_task_id'));
    }

    private function wbsPayload(Project $project, int $wbsTaskId): array
    {
        return [
            'project_id' => $project->id,
            'scope' => 'struktur',
            'description' => 'Pembesian pile cap zona A',
            'planned_start' => '2026-04-01',
            'duration_days' => 14,
            'wbs_task_id' => $wbsTaskId,
        ];
    }

    private function wbsTask(Project $project, string $code, string $name, ?int $parentId = null, ?int $boqItemId = null): WbsTask
    {
        return WbsTask::query()->create([
            'project_id' => $project->id,
            'parent_id' => $parentId,
            'boq_item_id' => $boqItemId,
            'wbs_code' => $code,
            'name' => $name,
            'weight_pct' => 0,
            'progress_pct' => 0,
        ]);
    }

    public function test_line_submittals_must_belong_to_the_same_project(): void
    {
        $projectA = $this->project();
        $projectB = $this->project(['code' => 'PRJ-2026-092', 'name' => 'Proyek Lain']);
        $foreign = $this->submittal($this->drawing($projectB));

        $this->admin();
        $response = $this->postJson('api/engineering/ipp', [
            'project_id' => $projectA->id,
            'scope' => 'struktur',
            'description' => 'Pekerjaan galian',
            'planned_start' => '2026-04-01',
            'duration_days' => 7,
            'drawings' => [['drawing_submittal_id' => $foreign->id]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString($foreign->code, (string) $response->json('message'));
    }
}
