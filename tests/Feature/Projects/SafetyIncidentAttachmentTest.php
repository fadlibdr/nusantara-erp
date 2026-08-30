<?php

namespace Tests\Feature\Projects;

use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\SafetyIncident;
use Tests\ErpTestCase;

/**
 * P6 — temuan panduan §7.7: "Insiden K3 tidak bisa menampung foto." Insiden
 * kini terdaftar di AttachableDocuments sebagai 'projects/safety-incidents',
 * jadi foto kejadian menempel pada insidennya sendiri, bukan lagi dititipkan
 * ke laporan harian dengan nomor insiden di keterangan.
 */
class SafetyIncidentAttachmentTest extends ErpTestCase
{
    use BaselineFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** A one-pixel PNG — real bytes, so finfo agrees with the extension. */
    private function png(): string
    {
        return base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function incident(): SafetyIncident
    {
        return SafetyIncident::query()->create([
            'project_id' => $this->grahaProject()->id,
            'occurred_at' => '2026-08-10 09:30:00',
            'severity' => 'near_miss',
            'category' => 'struck_by_object',
            'description' => 'Material jatuh dari lantai 5, nyaris mengenai pekerja.',
            'status' => 'open',
        ]);
    }

    public function test_an_incident_photo_attaches_to_the_incident_itself(): void
    {
        $incident = $this->incident();
        $editor = $this->userWith('prj.update');

        $this->actingAs($editor)->postJson('/api/core/attachments', [
            'document_type' => 'projects/safety-incidents',
            'document_id' => $incident->id,
            'filename' => 'material-jatuh-lantai5.png',
            'content' => $this->png(),
            'caption' => 'Titik jatuh material di zona B',
        ])->assertCreated();

        $listed = $this->actingAs($this->userWith('prj.view'))->getJson(
            '/api/core/attachments?document_type=projects/safety-incidents&document_id='.$incident->id
        )->assertOk()->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame('material-jatuh-lantai5.png', $listed[0]['original_name']);
    }

    /** Izin mengikuti modul pemilik: melampirkan butuh prj.update, bukan sekadar lihat. */
    public function test_attaching_requires_the_projects_update_permission(): void
    {
        $incident = $this->incident();

        $this->actingAs($this->userWith('prj.view'))->postJson('/api/core/attachments', [
            'document_type' => 'projects/safety-incidents',
            'document_id' => $incident->id,
            'filename' => 'foto.png',
            'content' => $this->png(),
        ])->assertStatus(403);
    }
}
