<?php

namespace Tests\Feature\Estimation;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Services\BoqService;
use Modules\Estimation\Services\RapService;
use Tests\ErpTestCase;

/**
 * The two forms that write the same records the document importer writes.
 *
 * The importer refuses a section replacement that would cascade a RAP's lines
 * away, and it routes every RAP write through RapService's own editability
 * guard. PUT /boqs/{id} and PUT /cost-budgets/{id} reach the identical rows, and
 * until now the form was the looser of the two doors — "the importer refuses
 * what the form allows" is not a rule anybody can be taught, and the damage is
 * the same whichever door it came through.
 *
 * What is asserted here is both halves of each guard: the write it refuses, and
 * the write it must go on allowing.
 */
class EstimationFormGuardsTest extends ErpTestCase
{
    use EstimationImportFixtures;

    // ------------------------------------------------- replacing BOQ sections

    /**
     * est_cost_budget_items.boq_item_id is constrained->cascadeOnDelete, so
     * replacing the sections DELETES the lines of a RAP that can no longer be
     * regenerated — leaving a submitted budget of Rp 0 that still reads as
     * submitted.
     */
    public function test_replacing_the_sections_of_a_boq_a_submitted_rap_was_built_from_is_refused(): void
    {
        $boq = $this->boqWithBudget(DocumentStatus::Submitted);

        $response = $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/boqs/{$boq->id}", [
                'title' => 'Ditulis ulang',
                'sections' => $this->replacementSections(),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('RAP/', $response->json('message'));
        $this->assertStringContainsString('terhapus', $response->json('message'));

        // Nothing moved: the BOQ still holds its line and the RAP its budget.
        $this->assertSame('Pembersihan lahan', BoqItem::query()->sole()->description);
        $this->assertGreaterThan(0, CostBudget::query()->sole()->items()->count());
    }

    /**
     * prj_wbs_tasks.boq_item_id has no constraint at all, so the link would
     * dangle: MaterialVarianceService reads the BOQ item through it for every
     * leaf task's theory quantity and a dangling id computes none.
     */
    public function test_replacing_the_sections_of_a_boq_a_wbs_task_points_at_is_refused(): void
    {
        $boq = $this->plainBoq();

        DB::table('prj_wbs_tasks')->insert([
            'project_id' => $this->project(),
            'boq_item_id' => (int) BoqItem::query()->value('id'),
            'wbs_code' => 'A.1',
            'name' => 'Pembersihan lahan',
            'weight_pct' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/boqs/{$boq->id}", [
                'title' => 'Ditulis ulang',
                'sections' => $this->replacementSections(),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('tugas WBS proyek', $response->json('message'));
        $this->assertSame('Pembersihan lahan', BoqItem::query()->sole()->description);
    }

    /** With nothing pointing at it, a draft BOQ is rewritten exactly as before. */
    public function test_replacing_the_sections_of_a_boq_nothing_points_at_is_allowed(): void
    {
        $boq = $this->plainBoq();

        $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/boqs/{$boq->id}", [
                'title' => 'Ditulis ulang',
                'sections' => $this->replacementSections(),
            ])
            ->assertOk();

        $item = BoqItem::query()->sole();

        $this->assertSame('Galian tanah pondasi', $item->description);
        $this->assertEqualsWithDelta(38_250_000.0, (float) $boq->refresh()->total, 0.01);
    }

    /**
     * The guard is on the `sections` key, not on the request.
     *
     * The BOQ form sends only the header fields — bagian and item are managed
     * from the detail screen — so renaming or re-linking a BOQ a RAP was built
     * from stays possible. Nothing is being replaced, so nothing cascades.
     */
    public function test_a_header_only_edit_of_the_same_boq_is_still_allowed(): void
    {
        $boq = $this->boqWithBudget(DocumentStatus::Submitted);

        $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/boqs/{$boq->id}", ['title' => 'Judul diperbaiki'])
            ->assertOk();

        $this->assertSame('Judul diperbaiki', $boq->refresh()->title);
        $this->assertSame('Pembersihan lahan', BoqItem::query()->sole()->description);
        $this->assertGreaterThan(0, CostBudget::query()->sole()->items()->count());
    }

    /**
     * Status stays the first rule: an approved BOQ refuses with the sentence it
     * always refused with, not with a complaint about a RAP it was never going
     * to reach.
     */
    public function test_an_approved_boq_still_refuses_on_its_status(): void
    {
        $boq = $this->plainBoq();
        $boq->forceFill(['status' => DocumentStatus::Approved])->save();

        $response = $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/boqs/{$boq->id}", [
                'title' => 'Ditulis ulang',
                'sections' => $this->replacementSections(),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be edited while status is approved', $response->json('message'));
    }

    // ------------------------------------------------------- editing a RAP

    /**
     * One editability rule, one sentence, whichever door was tried — the
     * controller no longer keeps a second copy of it to drift.
     */
    public function test_an_approved_rap_refuses_with_the_same_sentence_the_service_uses(): void
    {
        $budget = $this->budgetFor($this->plainBoq());
        $budget->forceFill(['status' => DocumentStatus::Approved])->save();

        $response = $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/cost-budgets/{$budget->id}", ['target_margin_pct' => 20]);

        $response->assertStatus(422);
        $this->assertSame(
            "RAP {$budget->code} cannot be edited while status is approved.",
            $response->json('message'),
        );
        $this->assertEqualsWithDelta(15.0, (float) $budget->refresh()->target_margin_pct, 0.0001);
    }

    /** A draft RAP is edited exactly as before. */
    public function test_a_draft_rap_is_edited_through_the_service(): void
    {
        $budget = $this->budgetFor($this->plainBoq());

        $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/cost-budgets/{$budget->id}", [
                'target_margin_pct' => 20,
                'notes' => 'Margin dinaikkan',
            ])
            ->assertOk();

        $budget->refresh();

        $this->assertEqualsWithDelta(20.0, (float) $budget->target_margin_pct, 0.0001);
        $this->assertSame('Margin dinaikkan', $budget->notes);
    }

    /**
     * Clearing the Proyek field means "the BOQ's project", never "no project".
     *
     * BaselineService finds a project's RAP with where('project_id', …), so a
     * null there detaches the budget from every baseline and EVM report that
     * would have found it while the RAP's own screen still looks complete.
     */
    public function test_clearing_the_project_on_a_rap_falls_back_to_the_boqs_project(): void
    {
        $projectId = $this->project();
        $boq = $this->plainBoq();
        $boq->forceFill(['project_id' => $projectId])->save();

        $budget = $this->budgetFor($boq);

        $this->actingAs($this->adminUser())
            ->putJson("/api/estimation/cost-budgets/{$budget->id}", ['project_id' => null])
            ->assertOk();

        $this->assertSame($projectId, (int) $budget->refresh()->project_id);
    }

    // ------------------------------------------------------------------ setup

    /** A draft BOQ of one line, nothing pointing at it. */
    private function plainBoq(): Boq
    {
        app(BoqService::class)->create([
            'title' => 'Gedung Kantor Graha Sentosa',
            'sections' => [[
                'section_no' => 'I',
                'name' => 'Pekerjaan Persiapan',
                'items' => [[
                    'wbs_code' => '1.1',
                    'description' => 'Pembersihan lahan',
                    'qty' => 1500,
                    'unit' => 'm2',
                    'unit_price' => 12_500,
                ]],
            ]],
        ]);

        return Boq::query()->sole();
    }

    /** The same BOQ with a RAP generated from it, at $status. */
    private function boqWithBudget(DocumentStatus $status): Boq
    {
        $boq = $this->plainBoq();

        $this->budgetFor($boq)->forceFill(['status' => $status])->save();

        return $boq;
    }

    private function budgetFor(Boq $boq): CostBudget
    {
        $rap = app(RapService::class);
        $budget = $rap->create(['boq_id' => $boq->id, 'target_margin_pct' => 15]);

        return $rap->generateFromBoq($budget);
    }

    /** @return array<int, array<string, mixed>> */
    private function replacementSections(): array
    {
        return [[
            'section_no' => 'I',
            'name' => 'Pekerjaan Persiapan',
            'items' => [[
                'wbs_code' => '1.1',
                'description' => 'Galian tanah pondasi',
                'qty' => 450,
                'unit' => 'm3',
                'unit_price' => 85_000,
            ]],
        ]];
    }
}
