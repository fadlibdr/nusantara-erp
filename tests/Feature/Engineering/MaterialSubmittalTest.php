<?php

namespace Tests\Feature\Engineering;

use Tests\ErpTestCase;

/**
 * P1-ENG — persetujuan material (SMS). Same four FM-10 stamps, same recorded
 * fact + maker-checker on the recording as the drawing submittal; what differs
 * is only the subject (a material, optionally an inventory item) — so this
 * suite pins the SMS-specific pieces and the shared refusal wording.
 */
class MaterialSubmittalTest extends ErpTestCase
{
    use EngineeringFixtures;

    public function test_a_material_submittal_gets_an_sms_number(): void
    {
        $submittal = $this->materialSubmittal($this->project());

        $this->assertMatchesRegularExpression('#^SMS/\d{4}/[IVX]+/\d{4}$#', $submittal->code);
        $this->assertNull($submittal->decision);
        $this->assertFalse((bool) $submittal->sample_attached);
    }

    public function test_recording_a_decision_stamps_it_with_the_indonesian_label(): void
    {
        $submittal = $this->materialSubmittal($this->project());

        $this->recorder();
        $response = $this->postJson("api/engineering/material-submittals/{$submittal->id}/decision", [
            'decision' => 'revise_resubmit',
            'decided_at' => '2026-03-15',
            'notes' => 'Lampirkan mill certificate produsen.',
        ]);

        $response->assertOk();
        $this->assertSame('Revisi & ajukan ulang', $response->json('data.decision_label'));
        $this->assertSame('2026-03-15', $submittal->fresh()->decided_at->toDateString());
    }

    public function test_the_creator_cannot_record_their_own_material_decision(): void
    {
        $submittal = $this->materialSubmittal($this->project());

        $this->admin();
        $this->postJson("api/engineering/material-submittals/{$submittal->id}/decision", [
            'decision' => 'approved',
            'decided_at' => '2026-03-15',
        ])->assertStatus(422);
    }

    public function test_a_decided_material_submittal_refuses_edits(): void
    {
        $submittal = $this->materialSubmittal($this->project());

        $this->recorder();
        $this->postJson("api/engineering/material-submittals/{$submittal->id}/decision", [
            'decision' => 'approved',
            'decided_at' => '2026-03-15',
        ])->assertOk();

        $this->admin();
        $response = $this->putJson("api/engineering/material-submittals/{$submittal->id}", [
            'material_name' => 'Material Lain',
        ]);

        $response->assertStatus(422);
        // The refusal names the stamp already on the sheet.
        $this->assertStringContainsString('Disetujui', (string) $response->json('message'));
    }
}
