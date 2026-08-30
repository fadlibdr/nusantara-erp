<?php

namespace Tests\Unit\Core;

use Illuminate\Support\Carbon;
use LogicException;
use Modules\Core\Models\NumberSequence;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Core\Fixtures\CustomColumnDocument;
use Tests\Unit\Core\Fixtures\NumberedDocument;
use Tests\Unit\Core\Fixtures\ProjectScopedDocument;
use Tests\Unit\Core\Fixtures\ProtectedColumnDocument;
use Tests\Unit\Core\Fixtures\TestDocumentSchema;
use Tests\Unit\Core\Fixtures\UntypedDocument;

/**
 * The creating hook that stamps a document code. Clock frozen at 15 July 2026
 * so the expected numbers are the July shapes.
 */
class HasDocumentNumberTest extends ErpTestCase
{
    use TestDocumentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestDocumentTable();
        Carbon::setTestNow('2026-07-15 09:00:00');
    }

    public function test_it_fills_the_code_on_create(): void
    {
        $document = NumberedDocument::query()->create(['title' => 'Pesanan pembelian']);

        // documentType = PO => config/erp.php documents.PO = 'PO/{Y}/{RM}/{N4}'
        $this->assertSame('PO/2026/VII/0001', $document->code);
        $this->assertDatabaseHas('test_documents', ['id' => $document->id, 'code' => 'PO/2026/VII/0001']);
    }

    public function test_consecutive_documents_take_consecutive_numbers(): void
    {
        $first = NumberedDocument::query()->create(['title' => 'PO pertama']);
        $second = NumberedDocument::query()->create(['title' => 'PO kedua']);

        $this->assertSame('PO/2026/VII/0001', $first->code);
        $this->assertSame('PO/2026/VII/0002', $second->code);
    }

    public function test_an_explicitly_supplied_code_is_never_overwritten(): void
    {
        $document = NumberedDocument::query()->create([
            'code' => 'PO/2019/XII/0042',
            'title' => 'Dokumen migrasi',
        ]);

        $this->assertSame('PO/2019/XII/0042', $document->code);
        // and no number was burned on the sequence.
        $this->assertDatabaseMissing('core_number_sequences', ['type' => 'PO']);
    }

    public function test_a_supplied_code_does_not_shift_the_next_generated_number(): void
    {
        NumberedDocument::query()->create(['code' => 'PO/2019/XII/0042', 'title' => 'Dokumen migrasi']);
        $generated = NumberedDocument::query()->create(['title' => 'PO baru']);

        $this->assertSame('PO/2026/VII/0001', $generated->code);
        $this->assertSame(1, (int) NumberSequence::query()->where('type', 'PO')->value('last_number'));
    }

    public function test_an_empty_code_is_treated_as_missing(): void
    {
        $document = NumberedDocument::query()->create(['code' => '', 'title' => 'Kode kosong']);

        $this->assertSame('PO/2026/VII/0001', $document->code);
    }

    public function test_it_can_fill_a_column_other_than_code(): void
    {
        $document = CustomColumnDocument::query()->create(['title' => 'Permintaan pembelian']);

        // documentType = PR, documentNumberColumn = doc_no
        $this->assertSame('PR/2026/VII/0001', $document->doc_no);
        $this->assertNull($document->code);
    }

    public function test_the_column_override_may_be_protected_as_the_trait_documents(): void
    {
        // The trait's docblock spells the override "protected string
        // $documentNumberColumn"; the creating hook is declared inside the
        // model's own scope, so a protected property is still readable.
        $document = ProtectedColumnDocument::query()->create(['title' => 'Permintaan pembelian']);

        $this->assertSame('PR/2026/VII/0001', $document->doc_no);
        $this->assertNull($document->code);
    }

    public function test_a_model_without_a_document_type_is_left_unnumbered(): void
    {
        $document = UntypedDocument::query()->create(['title' => 'Bukan dokumen bernomor']);

        $this->assertNull($document->code);
        $this->assertSame(0, NumberSequence::query()->count());
    }

    public function test_each_model_draws_from_its_own_type_sequence(): void
    {
        $po = NumberedDocument::query()->create(['title' => 'PO']);
        $pr = CustomColumnDocument::query()->create(['title' => 'PR']);
        $po2 = NumberedDocument::query()->create(['title' => 'PO lagi']);

        $this->assertSame('PO/2026/VII/0001', $po->code);
        $this->assertSame('PR/2026/VII/0001', $pr->doc_no);
        $this->assertSame('PO/2026/VII/0002', $po2->code);
    }

    public function test_the_generated_code_follows_the_settings_layer(): void
    {
        $this->setSetting('documents.PO', 'PO-{Y}-{N5}');

        $document = NumberedDocument::query()->create(['title' => 'Format khusus']);

        $this->assertSame('PO-2026-00001', $document->code);
    }

    // ---------------------------------------------------------------- {PROJ} (P8)

    private function project(string $code): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => "Proyek {$code}",
            'type' => 'construction',
        ]);
    }

    public function test_a_proj_mask_renders_the_project_code_and_scopes_the_counter(): void
    {
        $this->setSetting('documents.PO', 'PO/{PROJ}/{Y}/{RM}/{N4}');

        $alpha = $this->project('PRJ-2026-101');
        $beta = $this->project('PRJ-2026-102');

        $first = ProjectScopedDocument::query()->create(['title' => 'A1', 'project_id' => $alpha->id]);
        $other = ProjectScopedDocument::query()->create(['title' => 'B1', 'project_id' => $beta->id]);
        $second = ProjectScopedDocument::query()->create(['title' => 'A2', 'project_id' => $alpha->id]);

        // {PROJ} = prj_projects.code, and each project counts alone.
        $this->assertSame('PO/PRJ-2026-101/2026/VII/0001', $first->code);
        $this->assertSame('PO/PRJ-2026-102/2026/VII/0001', $other->code);
        $this->assertSame('PO/PRJ-2026-101/2026/VII/0002', $second->code);
    }

    public function test_a_token_less_mask_never_touches_the_project(): void
    {
        // Shipped documents.PO carries no {PROJ}: a project-bound document
        // numbers exactly like before — the same bytes, one shared counter.
        $alpha = $this->project('PRJ-2026-103');

        $document = ProjectScopedDocument::query()->create(['title' => 'PO', 'project_id' => $alpha->id]);

        $this->assertSame('PO/2026/VII/0001', $document->code);
        $this->assertDatabaseHas('core_number_sequences', ['type' => 'PO', 'year' => 2026, 'scope' => '']);
    }

    public function test_a_proj_mask_on_a_model_without_a_project_relation_fails_loudly(): void
    {
        // Configuration error: {PROJ} switched on for a type whose model
        // cannot answer "proyek yang mana?" — the mint must stop.
        $this->setSetting('documents.PO', 'PO/{PROJ}/{Y}/{RM}/{N4}');

        try {
            NumberedDocument::query()->create(['title' => 'Tanpa relasi proyek']);
            $this->fail('Expected the mint to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak punya relasi proyek', $e->getMessage());
            $this->assertStringContainsString('documents.PO', $e->getMessage());
        }

        $this->assertDatabaseCount('test_documents', 0);
        $this->assertSame(0, NumberSequence::query()->count());
    }

    public function test_a_proj_mask_on_a_document_without_a_project_fails_loudly(): void
    {
        $this->setSetting('documents.PO', 'PO/{PROJ}/{Y}/{RM}/{N4}');

        try {
            ProjectScopedDocument::query()->create(['title' => 'Proyek belum diisi']);
            $this->fail('Expected the mint to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum menunjuk proyek', $e->getMessage());
        }

        $this->assertDatabaseCount('test_documents', 0);
    }
}
