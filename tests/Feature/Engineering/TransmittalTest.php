<?php

namespace Tests\Feature\Engineering;

use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\Transmittal;
use Tests\ErpTestCase;

/**
 * P1-ENG — transmittal: the cover sheet that proves WHICH documents crossed the
 * table and WHO signed for them. Lines morph to either submittal type through a
 * wire vocabulary ('drawing_submittal' / 'material_submittal' / 'lainnya') —
 * never a class name — and the tanda-terima action is what locks the sheet.
 */
class TransmittalTest extends ErpTestCase
{
    use EngineeringFixtures;

    private function transmittal(array $overrides = []): Transmittal
    {
        $project = $overrides['__project'] ?? $this->project();
        unset($overrides['__project']);

        $this->admin();
        $response = $this->postJson('api/engineering/transmittals', array_merge([
            'project_id' => $project->id,
            'direction' => 'keluar',
            'to_party' => 'PT Mitra Cipta Konsultan (MK)',
            'transmittal_date' => '2026-03-06',
            'lines' => [
                ['kind' => 'lainnya', 'description' => 'Metode kerja pengecoran, 1 bundel'],
            ],
        ], $overrides));

        $response->assertCreated();

        return Transmittal::query()->findOrFail($response->json('data.id'));
    }

    public function test_a_transmittal_gets_a_trm_number_and_carries_its_lines(): void
    {
        $project = $this->project();
        $submittal = $this->submittal($this->drawing($project));

        $transmittal = $this->transmittal([
            '__project' => $project,
            'lines' => [
                ['kind' => 'drawing_submittal', 'document_id' => $submittal->id],
                ['kind' => 'lainnya', 'description' => 'Sampel kubus beton, 3 buah'],
            ],
        ]);

        $this->assertMatchesRegularExpression('#^TRM/\d{4}/[IVX]+/\d{4}$#', $transmittal->code);
        $this->assertSame(2, $transmittal->lines()->count());

        $line = $transmittal->lines()->orderBy('id')->first();
        $this->assertSame(DrawingSubmittal::class, $line->document_type);
        $this->assertSame($submittal->id, (int) $line->document_id);
        // The description is filled from the submittal itself, not left blank.
        $this->assertStringContainsString($submittal->code, (string) $line->description);
    }

    public function test_an_unknown_line_kind_is_refused(): void
    {
        $this->admin();
        $project = $this->project();

        $this->postJson('api/engineering/transmittals', [
            'project_id' => $project->id,
            'direction' => 'keluar',
            'to_party' => 'MK',
            'transmittal_date' => '2026-03-06',
            'lines' => [
                ['kind' => 'App\\Models\\User', 'document_id' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_a_line_document_from_another_project_is_refused_by_code(): void
    {
        $projectA = $this->project();
        $projectB = $this->project(['code' => 'PRJ-2026-093', 'name' => 'Proyek Lain']);
        $foreign = $this->submittal($this->drawing($projectB));

        $this->admin();
        $response = $this->postJson('api/engineering/transmittals', [
            'project_id' => $projectA->id,
            'direction' => 'keluar',
            'to_party' => 'MK',
            'transmittal_date' => '2026-03-06',
            'lines' => [
                ['kind' => 'drawing_submittal', 'document_id' => $foreign->id],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString($foreign->code, (string) $response->json('message'));
    }

    public function test_the_tanda_terima_action_records_receiver_and_locks_the_sheet(): void
    {
        $transmittal = $this->transmittal();

        $response = $this->postJson("api/engineering/transmittals/{$transmittal->id}/terima", [
            'received_by' => 'Ir. Bagus Halim (MK)',
            'received_at' => '2026-03-06 14:30',
        ]);

        $response->assertOk();
        $transmittal->refresh();
        $this->assertSame('Ir. Bagus Halim (MK)', $transmittal->received_by);
        $this->assertNotNull($transmittal->received_at);

        // Received = signed for. The register no longer accepts edits…
        $update = $this->putJson("api/engineering/transmittals/{$transmittal->id}", [
            'to_party' => 'Pihak lain',
        ]);
        $update->assertStatus(422);
        $this->assertStringContainsString('Ir. Bagus Halim (MK)', (string) $update->json('message'));

        // …nor a second receipt.
        $this->postJson("api/engineering/transmittals/{$transmittal->id}/terima", [
            'received_by' => 'Orang Lain',
        ])->assertStatus(422);
    }
}
