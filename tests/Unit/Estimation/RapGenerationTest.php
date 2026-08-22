<?php

namespace Tests\Unit\Estimation;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Enums\CostCategory;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Models\CostBudgetItem;
use Tests\ErpTestCase;

/**
 * RAP — Rencana Anggaran Pelaksanaan, the internal cost budget deflated out of
 * the selling BOQ at a target margin:
 *
 *   budget per item = amount / (1 + margin / 100)
 *   total_budget    = sum of those (== boq total / (1 + margin / 100))
 *
 * With an AHSP behind the item the budget is split per cost category
 * proportionally to the component mix; the overhead line absorbs the residue.
 */
class RapGenerationTest extends ErpTestCase
{
    use EstimationFixtures;

    /**
     * Two lump-sum items: 100.000.000 + 50.000.000 = 150.000.000.
     */
    private function makeLumpSumBoq(): Boq
    {
        return $this->boqs()->create([
            'title' => 'RAB paket subkon',
            'sections' => [
                ['section_no' => 'A', 'name' => 'Pekerjaan Sipil', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Paket pondasi', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 100000000],
                    ['wbs_code' => 'A.2', 'description' => 'Paket dinding', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 50000000],
                ]],
            ],
        ]);
    }

    /**
     * One AHSP-backed item: upah 200.000 + bahan 800.000 = dasar 1.000.000,
     * overhead 10% -> harga satuan 1.100.000. Qty 10 -> nilai jual 11.000.000.
     */
    private function makeAhspBoq(): array
    {
        $ahsp = $this->ahsps()->create([
            'code' => 'AHSP-SPLIT',
            'name' => 'Pekerjaan uji pembagian biaya',
            'unit' => 'm2',
            'category' => 'sipil',
            'overhead_pct' => 10,
            'components' => [
                ['component_type' => 'labor', 'name' => 'Upah', 'unit' => 'OH', 'coefficient' => 1, 'unit_price' => 200000],
                ['component_type' => 'material', 'name' => 'Bahan', 'unit' => 'm2', 'coefficient' => 1, 'unit_price' => 800000],
            ],
        ]);

        $boq = $this->boqs()->create([
            'title' => 'RAB analisa',
            'sections' => [
                ['section_no' => 'A', 'name' => 'Pekerjaan Sipil', 'items' => [
                    ['wbs_code' => 'A.1', 'ahsp_id' => $ahsp->id, 'qty' => 10],
                ]],
            ],
        ]);

        return [$ahsp, $boq];
    }

    private function makeBudget(Boq $boq, float $margin): CostBudget
    {
        return $this->raps()->create([
            'boq_id' => $boq->id,
            'target_margin_pct' => $margin,
        ]);
    }

    // --------------------------------------------------------- the headline math

    public function test_total_budget_is_the_boq_total_deflated_by_the_target_margin(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        $this->raps()->generateFromBoq($budget);

        // 150.000.000 / 1,15 = 130.434.782,6087 -> 130.434.782,61
        $this->assertSame(150000000.0, (float) $boq->refresh()->total);
        $this->assertSame(130434782.61, (float) $budget->refresh()->total_budget);
    }

    public function test_each_line_is_its_own_boq_amount_deflated(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        $this->raps()->generateFromBoq($budget);

        $amounts = $budget->items()->orderBy('id')->pluck('amount')
            ->map(fn ($amount): float => (float) $amount)->all();

        // 100.000.000 / 1,15 = 86.956.521,7391 -> 86.956.521,74
        //  50.000.000 / 1,15 = 43.478.260,8696 -> 43.478.260,87
        $this->assertSame([86956521.74, 43478260.87], $amounts);
    }

    public function test_a_zero_margin_budget_equals_the_boq_total(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 0);

        $this->raps()->generateFromBoq($budget);

        // 150.000.000 / (1 + 0/100) = 150.000.000
        $this->assertSame(150000000.0, (float) $budget->refresh()->total_budget);
    }

    public function test_a_negative_margin_inflates_the_budget_above_the_selling_price(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, -25);

        $this->raps()->generateFromBoq($budget);

        // rugi terencana: 150.000.000 / 0,75 = 200.000.000
        $this->assertSame(200000000.0, (float) $budget->refresh()->total_budget);
    }

    public function test_the_margin_argument_overrides_the_stored_target(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        $this->raps()->generateFromBoq($budget, 20);

        // 150.000.000 / 1,20 = 125.000.000, dan target tersimpan ikut berubah
        $this->assertSame(20.0, (float) $budget->refresh()->target_margin_pct);
        $this->assertSame(125000000.0, (float) $budget->total_budget);
    }

    // ------------------------------------------------------- category splitting

    public function test_an_item_without_an_ahsp_becomes_a_single_subcon_line(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        $this->raps()->generateFromBoq($budget);

        $this->assertSame(2, $budget->items()->count());
        $this->assertSame(
            [CostCategory::Subcon, CostCategory::Subcon],
            $budget->items()->orderBy('id')->get()->map(fn (CostBudgetItem $item) => $item->cost_category)->all(),
        );
    }

    public function test_an_ahsp_item_splits_across_labor_material_and_overhead(): void
    {
        [, $boq] = $this->makeAhspBoq();
        $budget = $this->makeBudget($boq, 10);

        $this->raps()->generateFromBoq($budget);

        $byCategory = $budget->items()->get()
            ->mapWithKeys(fn (CostBudgetItem $item): array => [$item->cost_category->value => (float) $item->amount]);

        // nilai jual 11.000.000 ; target = 11.000.000 / 1,10 = 10.000.000
        // dasar upah 200.000 + bahan 800.000 = 1.000.000 ; overhead 100.000 ; grand 1.100.000
        // upah  = 10.000.000 * 200.000 / 1.100.000 = 1.818.181,8181 -> 1.818.181,82
        // bahan = 10.000.000 * 800.000 / 1.100.000 = 7.272.727,2727 -> 7.272.727,27
        // sisa (overhead) = 10.000.000 - 9.090.909,09 = 909.090,91
        $this->assertSame(1818181.82, $byCategory['labor']);
        $this->assertSame(7272727.27, $byCategory['material']);
        $this->assertSame(909090.91, $byCategory['overhead']);

        // Baris kategori selalu berjumlah tepat sebesar target.
        $this->assertSame(10000000.0, (float) $budget->refresh()->total_budget);
    }

    public function test_the_line_unit_price_is_the_amount_spread_over_the_quantity(): void
    {
        [, $boq] = $this->makeAhspBoq();
        $budget = $this->makeBudget($boq, 10);

        $this->raps()->generateFromBoq($budget);

        $byCategory = $budget->items()->get()
            ->mapWithKeys(fn (CostBudgetItem $item): array => [$item->cost_category->value => (float) $item->unit_price]);

        // qty 10 -> 1.818.181,82/10 = 181.818,182 -> 181.818,18 ; 7.272.727,27/10 -> 727.272,73
        $this->assertSame(181818.18, $byCategory['labor']);
        $this->assertSame(727272.73, $byCategory['material']);
        $this->assertSame(90909.09, $byCategory['overhead']);
    }

    public function test_every_line_points_back_at_its_boq_item(): void
    {
        [, $boq] = $this->makeAhspBoq();
        $budget = $this->makeBudget($boq, 10);

        $this->raps()->generateFromBoq($budget);

        $boqItemId = $boq->items()->firstOrFail()->id;

        foreach ($budget->items()->get() as $line) {
            $this->assertSame($boqItemId, (int) $line->boq_item_id);
            $this->assertSame(10.0, (float) $line->qty);
            $this->assertSame('m2', $line->unit);
        }
    }

    public function test_an_ahsp_without_components_falls_back_to_a_subcon_line(): void
    {
        /** @var Ahsp $ahsp */
        $ahsp = $this->ahsps()->create([
            'code' => 'AHSP-NOCOMP',
            'name' => 'Paket tanpa analisa',
            'unit' => 'ls',
            'category' => 'elv',
            'overhead_pct' => 0,
            'components' => [],
        ]);

        $boq = $this->boqs()->create([
            'title' => 'RAB tanpa komponen',
            'sections' => [
                ['section_no' => 'A', 'name' => 'ELV', 'items' => [
                    ['wbs_code' => 'A.1', 'ahsp_id' => $ahsp->id, 'qty' => 1, 'unit_price' => 20000000],
                ]],
            ],
        ]);

        $budget = $this->makeBudget($boq, 25);
        $this->raps()->generateFromBoq($budget);

        // 20.000.000 / 1,25 = 16.000.000 sebagai satu baris subkon
        $this->assertSame(1, $budget->items()->count());
        $this->assertSame(CostCategory::Subcon, $budget->items()->firstOrFail()->cost_category);
        $this->assertSame(16000000.0, (float) $budget->refresh()->total_budget);
    }

    // ---------------------------------------------------------------- the guards

    public function test_regenerating_replaces_the_lines_instead_of_duplicating_them(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        $this->raps()->generateFromBoq($budget);
        $this->raps()->generateFromBoq($budget, 20);

        $this->assertSame(2, $budget->items()->count());
        $this->assertSame(2, CostBudgetItem::query()->count());
        // 150.000.000 / 1,20 = 125.000.000
        $this->assertSame(125000000.0, (float) $budget->refresh()->total_budget);
    }

    public function test_a_margin_of_minus_one_hundred_throws_and_writes_nothing(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        try {
            $this->raps()->generateFromBoq($budget, -100);
            $this->fail('Expected LogicException for a -100% margin.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('greater than -100%', $e->getMessage());
        }

        $this->assertSame(0, CostBudgetItem::query()->count());
        $this->assertSame(15.0, (float) $budget->refresh()->target_margin_pct);
        $this->assertSame(0.0, (float) $budget->total_budget);
    }

    public function test_a_margin_below_minus_one_hundred_throws(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        $this->expectException(LogicException::class);

        $this->raps()->generateFromBoq($budget, -150);
    }

    public function test_an_approved_rap_cannot_be_regenerated(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);
        $this->raps()->generateFromBoq($budget);

        $budget->refresh()->submit();
        $budget->approve($this->makeUser());

        try {
            $this->raps()->generateFromBoq($budget, 40);
            $this->fail('Expected LogicException when regenerating an approved RAP.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot be regenerated', $e->getMessage());
        }

        $fresh = CostBudget::query()->findOrFail($budget->id);

        $this->assertSame(DocumentStatus::Approved, $fresh->status);
        $this->assertSame(15.0, (float) $fresh->target_margin_pct);
        $this->assertSame(130434782.61, (float) $fresh->total_budget);
        $this->assertSame(2, $fresh->items()->count());
    }

    public function test_a_new_rap_starts_as_a_numbered_draft_with_no_lines(): void
    {
        $boq = $this->makeLumpSumBoq();
        $budget = $this->makeBudget($boq, 15);

        $this->assertStringStartsWith('RAP/', $budget->code);
        $this->assertSame(DocumentStatus::Draft, $budget->status);
        $this->assertSame(0.0, (float) $budget->total_budget);
        $this->assertSame(0, $budget->items()->count());
    }

    public function test_the_rap_inherits_the_project_link_from_the_boq(): void
    {
        $boq = $this->boqs()->create([
            'title' => 'RAB proyek',
            'project_id' => 42,
            'sections' => [
                ['section_no' => 'A', 'name' => 'Umum', 'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Perizinan', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 5000000],
                ]],
            ],
        ]);

        $budget = $this->makeBudget($boq, 10);

        $this->assertSame(42, (int) $budget->project_id);
    }
}
