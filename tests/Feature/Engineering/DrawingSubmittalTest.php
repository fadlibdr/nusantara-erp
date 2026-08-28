<?php

namespace Tests\Feature\Engineering;

use Modules\Engineering\Models\Drawing;
use Tests\ErpTestCase;

/**
 * P1-ENG — persetujuan shop drawing (SDS) sebagai transaksi.
 *
 * Keputusan MK adalah FAKTA TERCATAT, bukan siklus Approvable: MK bukan baris
 * users, dan keempat stempel FM-10 diketik dari lembar yang kembali. Maka yang
 * dijaga di sini adalah pencatatannya — siapa boleh mencatat (eng.approve,
 * bukan si pembuat submittal), apa yang boleh dicatat (empat stempel, sekali),
 * dan bagaimana revisi menggantikan pendahulunya (pola BaselineService:
 * superseded_at + superseded_by_id dalam satu transaksi).
 */
class DrawingSubmittalTest extends ErpTestCase
{
    use EngineeringFixtures;

    // ------------------------------------------------------------- register

    public function test_a_drawing_registers_with_its_own_number_and_starts_unsubmitted(): void
    {
        $this->admin();
        $project = $this->project();

        $response = $this->postJson('api/engineering/drawings', [
            'project_id' => $project->id,
            'number' => 'GPC-AR-201',
            'title' => 'Denah Arsitektur Lantai 2',
            'discipline' => 'arsitektur',
            'planned_submit_date' => '2026-04-01',
        ]);

        $response->assertCreated();
        $this->assertSame('belum_diajukan', $response->json('data.status'));
        // Nomor gambar milik drafter, bukan penomoran otomatis dokumen.
        $this->assertSame('GPC-AR-201', $response->json('data.number'));
    }

    public function test_the_same_drawing_number_cannot_register_twice_on_one_project(): void
    {
        $this->admin();
        $project = $this->project();
        $this->drawing($project);

        $this->postJson('api/engineering/drawings', [
            'project_id' => $project->id,
            'number' => 'GPC-ST-101',
            'title' => 'Duplikat',
            'discipline' => 'struktur',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------ numbering

    public function test_a_submittal_gets_an_sds_number_and_marks_the_drawing_submitted(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);

        $submittal = $this->submittal($drawing);

        $this->assertMatchesRegularExpression('#^SDS/\d{4}/[IVX]+/\d{4}$#', $submittal->code);
        $this->assertSame('diajukan', $drawing->fresh()->status->value);
    }

    // ------------------------------------------------------------- decision

    public function test_recording_a_decision_stamps_the_submittal_and_mirrors_the_drawing_status(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project));

        $this->recorder();
        $response = $this->postJson("api/engineering/drawing-submittals/{$submittal->id}/decision", [
            'decision' => 'approved_as_noted',
            'decided_at' => '2026-03-12',
            'notes' => 'Perbaiki notasi tulangan pada potongan A-A.',
        ]);

        $response->assertOk();
        $submittal->refresh();
        $this->assertSame('approved_as_noted', $submittal->decision->value);
        $this->assertSame('Disetujui dengan catatan', $response->json('data.decision_label'));
        $this->assertSame('2026-03-12', $submittal->decided_at->toDateString());
        $this->assertSame('approved_as_noted', $submittal->drawing->fresh()->status->value);
    }

    public function test_the_person_who_created_the_submittal_cannot_record_its_decision(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project));

        // The admin created it; the admin now tries to record the stamp.
        $this->admin();
        $response = $this->postJson("api/engineering/drawing-submittals/{$submittal->id}/decision", [
            'decision' => 'approved',
            'decided_at' => '2026-03-12',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('tidak boleh', (string) $response->json('message'));

        $this->assertNull($submittal->fresh()->decision);
    }

    public function test_a_decision_is_recorded_once_and_refuses_a_second_recording(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project));

        $this->recorder();
        $this->postJson("api/engineering/drawing-submittals/{$submittal->id}/decision", [
            'decision' => 'rejected',
            'decided_at' => '2026-03-12',
        ])->assertOk();

        $second = $this->postJson("api/engineering/drawing-submittals/{$submittal->id}/decision", [
            'decision' => 'approved',
            'decided_at' => '2026-03-13',
        ]);

        $second->assertStatus(422);
        $this->assertStringContainsString('sudah tercatat', (string) $second->json('message'));
        $this->assertSame('rejected', $submittal->fresh()->decision->value);
    }

    public function test_a_decision_outside_the_four_stamps_is_refused(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project));

        $this->recorder();
        $this->postJson("api/engineering/drawing-submittals/{$submittal->id}/decision", [
            'decision' => 'maybe',
            'decided_at' => '2026-03-12',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------- revision

    public function test_a_new_revision_supersedes_the_previous_one_in_the_same_transaction(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);

        $first = $this->submittal($drawing, ['revision' => 'R0']);
        $second = $this->submittal($drawing, ['revision' => 'R1', 'submitted_at' => '2026-03-20']);

        $first->refresh();
        $this->assertNotNull($first->superseded_at);
        $this->assertSame($second->id, (int) $first->superseded_by_id);
        $this->assertNull($second->fresh()->superseded_at);
        $this->assertSame('diajukan', $drawing->fresh()->status->value);
    }

    public function test_a_superseded_submittal_refuses_a_decision_and_names_its_successor(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);

        $first = $this->submittal($drawing, ['revision' => 'R0']);
        $second = $this->submittal($drawing, ['revision' => 'R1', 'submitted_at' => '2026-03-20']);

        $this->recorder();
        $response = $this->postJson("api/engineering/drawing-submittals/{$first->id}/decision", [
            'decision' => 'approved',
            'decided_at' => '2026-03-25',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString($second->code, (string) $response->json('message'));
    }

    public function test_the_same_revision_label_cannot_be_submitted_twice_for_one_drawing(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);
        $this->submittal($drawing, ['revision' => 'R0']);

        $this->admin();
        $this->postJson('api/engineering/drawing-submittals', [
            'drawing_id' => $drawing->id,
            'revision' => 'R0',
            'submitted_at' => '2026-03-21',
            'reviewer_party' => 'mk',
        ])->assertStatus(422);
    }
}
