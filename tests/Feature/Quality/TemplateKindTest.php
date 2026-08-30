<?php

namespace Tests\Feature\Quality;

use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Models\InspectionTemplate;
use Tests\ErpTestCase;

/**
 * P6 — kolom `jenis` pada pustaka template inspeksi: 'quality' (bawaan,
 * seluruh baris lama) dan '5r'. Checklist 5R adalah INSPEKSI BIASA atas
 * template ber-jenis 5r — seluruh mesin P1-QC (butir, hasil, verdict, guard
 * template terpakai) dipakai ulang, tidak ada mesin checklist paralel.
 */
class TemplateKindTest extends ErpTestCase
{
    use QualityFixtures;

    /**
     * Migrasi forward-only: baris yang tidak menyebut jenis adalah template
     * MUTU — bawaan kolomnya 'quality', sehingga seluruh pustaka Q1..Q31 yang
     * sudah ada tetap berperilaku persis seperti sebelum kolomnya lahir.
     */
    public function test_a_template_created_without_jenis_is_a_quality_template(): void
    {
        $template = $this->template(InspectionStage::Before);

        $this->assertSame('quality', $template->refresh()->jenis->value);
    }

    public function test_a_5r_template_is_created_through_the_api_and_listed_by_its_jenis(): void
    {
        $this->admin();

        $this->postJson('/api/quality/inspection-templates', [
            'code' => '5R1',
            'work_package' => 'Patroli 5R area kerja',
            'stage' => 'during',
            'jenis' => '5r',
            'items' => [
                ['check_text' => 'Ringkas: tidak ada barang tak terpakai di area', 'acceptance' => 'Area bebas barang tak terpakai'],
                ['check_text' => 'Rapi: material tersusun pada tempatnya', 'acceptance' => 'Material tersusun & berlabel'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.jenis', '5r');

        $listed = $this->getJson('/api/quality/inspection-templates?jenis=5r')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame('5R1', $listed[0]['code']);

        // Saringan kebalikannya tidak memuat template 5R.
        $quality = $this->getJson('/api/quality/inspection-templates?jenis=quality')
            ->assertOk()
            ->json('data');

        $this->assertSame([], array_values(array_filter($quality, fn (array $row): bool => $row['code'] === '5R1')));
    }

    public function test_an_unknown_jenis_is_refused(): void
    {
        $this->admin();

        $this->postJson('/api/quality/inspection-templates', [
            'code' => '5R9',
            'work_package' => 'Patroli 5R',
            'stage' => 'during',
            'jenis' => 'housekeeping',
        ])->assertStatus(422)->assertJsonValidationErrors(['jenis']);
    }

    /**
     * Checklist 5R menaiki mesin inspeksi P1-QC apa adanya: inspeksi atas
     * template jenis-5r terisi, verdict-nya DITURUNKAN dari baris (nok
     * menggagalkan lembar), persis seperti inspeksi mutu.
     */
    public function test_a_5r_checklist_is_an_ordinary_inspection_against_a_5r_template(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $template = $this->template(InspectionStage::During, ['code' => '5R2', 'jenis' => '5r', 'work_package' => 'Patroli 5R gudang site']);
        $items = $template->items()->orderBy('sort_order')->get();

        $this->admin();

        $response = $this->postJson('/api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-08-20',
            'results' => [
                ['template_item_id' => $items[0]->id, 'result' => 'ok'],
                ['template_item_id' => $items[1]->id, 'result' => 'nok', 'remark' => 'Tumpukan semen menghalangi jalur'],
            ],
        ])->assertCreated();

        $this->assertFalse((bool) $response->json('data.passed'), 'nok pada satu butir menggagalkan lembar 5R juga.');
    }

    /**
     * Guard P1-QC harus tetap berdiri dengan kolom baru: template (jenis apa
     * pun) yang butirnya sudah dipakai inspeksi terisi menolak tulis-ulang
     * butir dengan 422 yang sama.
     */
    public function test_the_filled_template_guard_still_holds_for_5r_templates(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $template = $this->template(InspectionStage::During, ['code' => '5R3', 'jenis' => '5r']);
        $items = $template->items()->orderBy('sort_order')->get();

        $this->admin();
        $this->postJson('/api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-08-21',
            'results' => [['template_item_id' => $items[0]->id, 'result' => 'ok']],
        ])->assertCreated();

        $this->admin();
        $this->putJson('/api/quality/inspection-templates/'.$template->id, [
            'code' => '5R3',
            'work_package' => (string) $template->work_package,
            'stage' => 'during',
            'jenis' => '5r',
            'items' => [
                ['check_text' => 'Butir pengganti', 'acceptance' => 'Kriteria pengganti'],
            ],
        ])->assertStatus(422)->assertJsonPath(
            'errors.items.0',
            'Template ini sudah dipakai inspeksi yang terisi; butirnya tidak bisa '
                .'ditulis ulang. Buat template versi baru untuk perubahan.',
        );
    }

    /**
     * Jenis pada template TERISI adalah sejarah, sama seperti butirnya:
     * membaliknya memindahkan patroli 5R lama ke saringan inspeksi mutu tanpa
     * jejak. Sebelum guard ini update() membalik jenis dengan bebas sementara
     * hanya replaceItems() yang dijaga — dua pintu, satu kunci.
     */
    public function test_jenis_of_a_filled_template_cannot_be_flipped(): void
    {
        $project = $this->project();
        $location = $this->location($project);
        $template = $this->template(InspectionStage::During, ['code' => '5R4', 'jenis' => '5r']);
        $items = $template->items()->orderBy('sort_order')->get();

        $this->admin();
        $this->postJson('/api/quality/inspections', [
            'project_id' => $project->id,
            'location_id' => $location->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-08-22',
            'results' => [['template_item_id' => $items[0]->id, 'result' => 'ok']],
        ])->assertCreated();

        $this->admin();
        $this->putJson('/api/quality/inspection-templates/'.$template->id, [
            'code' => '5R4',
            'work_package' => (string) $template->work_package,
            'stage' => 'during',
            'jenis' => 'quality',
        ])->assertStatus(422)->assertJsonPath(
            'errors.jenis.0',
            'Template ini sudah dipakai inspeksi yang terisi; jenisnya tidak bisa '
                .'diubah karena akan memindahkan inspeksi lama antar saringan. Buat template '
                .'versi baru untuk jenis yang berbeda.',
        );

        $this->assertSame('5r', $template->fresh()->jenis->value);

        // Jenis yang SAMA tetap boleh dikirim ulang — guard menolak perubahan,
        // bukan kehadiran kuncinya.
        $this->putJson('/api/quality/inspection-templates/'.$template->id, [
            'code' => '5R4',
            'work_package' => 'Housekeeping area fabrikasi',
            'stage' => 'during',
            'jenis' => '5r',
        ])->assertOk();
    }

    /** Template yang ada tidak berubah jenis diam-diam saat di-update tanpa kunci jenis. */
    public function test_updating_without_jenis_keeps_the_stored_kind(): void
    {
        $template = InspectionTemplate::query()->create([
            'code' => '5R4',
            'work_package' => 'Patroli 5R kantor site',
            'stage' => InspectionStage::During,
            'jenis' => '5r',
        ]);

        $this->admin();
        $this->putJson('/api/quality/inspection-templates/'.$template->id, [
            'code' => '5R4',
            'work_package' => 'Patroli 5R kantor & barak',
            'stage' => 'during',
        ])->assertOk();

        $this->assertSame('5r', $template->refresh()->jenis->value);
    }
}
