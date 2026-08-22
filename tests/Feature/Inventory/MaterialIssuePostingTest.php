<?php

namespace Tests\Feature\Inventory;

use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * GR/IR step 3 — consumption. Issuing material moves the moving-average value
 * off the balance sheet into the right cost account, and mirrors it into the
 * project cost ledger so realisasi can be compared against the RAP.
 */
class MaterialIssuePostingTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    /** Cross-module id: Projects owns prj_projects, there is no FK to satisfy. */
    private const PROJECT_ID = 77;

    private Warehouse $pusat;

    private Item $semen;

    private Item $oli;

    private Item $bor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->oli = $this->makeItem('Oli Hidrolik', [
            'unit' => 'ltr',
            'item_type' => ItemType::Sparepart,
        ]);
        $this->bor = $this->makeItem('Bor Beton Hilti', [
            'unit' => 'unit',
            'item_type' => ItemType::Tool,
        ]);

        $this->stock()->postReceipt($this->makeGrn($this->pusat, [
            [$this->semen, 100, 15000],
            [$this->oli, 20, 100000],
            [$this->bor, 5, 500000],
        ], '2026-03-01'));
    }

    public function test_a_project_issue_debits_material_cost_and_credits_inventory(): void
    {
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15')
        );

        // 40 zak * 15.000 (warehouse moving average) = 600.000
        $journal = $this->singleJournalFor('inventory_issue', (int) $issue->id);
        $this->assertPostedAndBalanced($journal, '2026-03-15');

        $lines = $this->linesByAccount($journal);

        $this->assertSame(['5-1100', '1-1400'], array_keys($lines));
        $this->assertSame(600000.0, $lines['5-1100']['debit']);
        $this->assertSame(600000.0, $lines['1-1400']['credit']);

        // Both legs carry the project so the project P&L is complete.
        $this->assertSame(self::PROJECT_ID, $lines['5-1100']['project_id']);
        $this->assertSame(self::PROJECT_ID, $lines['1-1400']['project_id']);

        // Stock left the warehouse at the same value.
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
    }

    public function test_a_project_issue_writes_exactly_one_material_project_cost_row_per_line(): void
    {
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15')
        );

        $costs = ProjectCost::query()->get();

        // The reference is the LINE, not the issue: record() upserts on
        // (reference, category), so a per-issue reference would collapse a bon
        // serving two work packages in one category into a single row — the
        // per-line shape is what lets wbs_task_id survive into the ledger.
        $this->assertCount(1, $costs);
        $this->assertSame(self::PROJECT_ID, (int) $costs[0]->project_id);
        $this->assertSame(CostCategory::Material, $costs[0]->cost_category);
        $this->assertSame('inventory_issue_item', $costs[0]->reference_type);
        $this->assertSame((int) $issue->items()->sole()->id, (int) $costs[0]->reference_id);
        $this->assertSame('2026-03-15', $costs[0]->cost_date->toDateString());
        $this->assertSame(600000.0, (float) $costs[0]->amount); // 40 * 15.000
    }

    public function test_material_and_sparepart_lines_are_summed_into_one_material_debit(): void
    {
        $issue = $this->stock()->postIssue($this->makeIssue($this->pusat, [
            [$this->semen, 40], // 40 *  15.000 = 600.000
            [$this->oli, 3],    //  3 * 100.000 = 300.000
        ], self::PROJECT_ID, '2026-03-15'));

        // Sparepart is a material cost, so both lines collapse onto 5-1100:
        // 600.000 + 300.000 = 900.000
        $lines = $this->linesByAccount($this->singleJournalFor('inventory_issue', (int) $issue->id));

        $this->assertCount(2, $lines);
        $this->assertSame(900000.0, $lines['5-1100']['debit']);
        $this->assertSame(900000.0, $lines['1-1400']['credit']);

        // The JOURNAL sums per account; the COST LEDGER stays per line, so each
        // row can carry its own work package. Same category, same total.
        $costs = ProjectCost::query()->orderBy('reference_id')->get();
        $this->assertCount(2, $costs);
        $this->assertSame(600000.0, (float) $costs[0]->amount);
        $this->assertSame(300000.0, (float) $costs[1]->amount);
        $this->assertSame(CostCategory::Material, $costs[0]->cost_category);
        $this->assertSame(CostCategory::Material, $costs[1]->cost_category);
        $this->assertSame(900000.0, (float) ProjectCost::query()->sum('amount'));
    }

    public function test_a_tool_line_is_costed_to_the_equipment_account(): void
    {
        $issue = $this->stock()->postIssue($this->makeIssue($this->pusat, [
            [$this->semen, 40], // 40 *  15.000 =   600.000 -> 5-1100 material
            [$this->bor, 2],    //  2 * 500.000 = 1.000.000 -> 5-1400 equipment
        ], self::PROJECT_ID, '2026-03-15'));

        $journal = $this->singleJournalFor('inventory_issue', (int) $issue->id);
        $this->assertPostedAndBalanced($journal, '2026-03-15');

        $lines = $this->linesByAccount($journal);

        $this->assertCount(3, $lines);
        $this->assertSame(600000.0, $lines['5-1100']['debit']);
        $this->assertSame(1000000.0, $lines['5-1400']['debit']);
        // One credit for the whole issue: 600.000 + 1.000.000 = 1.600.000
        $this->assertSame(1600000.0, $lines['1-1400']['credit']);
    }

    public function test_a_mixed_issue_splits_the_project_cost_per_category(): void
    {
        $this->stock()->postIssue($this->makeIssue($this->pusat, [
            [$this->semen, 40],
            [$this->bor, 2],
        ], self::PROJECT_ID, '2026-03-15'));

        $byCategory = ProjectCost::query()->get()
            ->mapWithKeys(fn (ProjectCost $cost): array => [
                $cost->cost_category->value => (float) $cost->amount,
            ])
            ->all();

        $this->assertSame(['material' => 600000.0, 'equipment' => 1000000.0], $byCategory);
    }

    public function test_an_issue_without_a_project_debits_general_expense_and_writes_no_project_cost(): void
    {
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], null, '2026-03-15')
        );

        // Overhead consumption: 40 * 15.000 = 600.000 to 6-4100, not 5-1100.
        $lines = $this->linesByAccount($this->singleJournalFor('inventory_issue', (int) $issue->id));

        $this->assertSame(['6-4100', '1-1400'], array_keys($lines));
        $this->assertSame(600000.0, $lines['6-4100']['debit']);
        $this->assertSame(600000.0, $lines['1-1400']['credit']);
        $this->assertNull($lines['6-4100']['project_id']);
        $this->assertNull($lines['1-1400']['project_id']);

        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_tool_issued_without_a_project_also_lands_in_general_expense(): void
    {
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->bor, 2]], null, '2026-03-15')
        );

        // Without a project there is no 5-xxxx HPP account to use, so the
        // equipment category still expenses to 6-4100: 2 * 500.000 = 1.000.000
        $lines = $this->linesByAccount($this->singleJournalFor('inventory_issue', (int) $issue->id));

        $this->assertSame(['6-4100', '1-1400'], array_keys($lines));
        $this->assertSame(1000000.0, $lines['6-4100']['debit']);
        $this->assertSame(1000000.0, $lines['1-1400']['credit']);
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_zero_valued_issue_moves_stock_without_a_journal_or_project_cost(): void
    {
        $pasir = $this->makeItem('Pasir Urug', ['unit' => 'm3']);
        $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$pasir, 10, 0]], '2026-03-02')
        );

        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$pasir, 4]], self::PROJECT_ID, '2026-03-15')
        );

        $this->assertSame(6.0, $this->balanceQty($this->pusat, $pasir));
        $this->assertNoJournalFor('inventory_issue', (int) $issue->id);
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_the_issue_journal_values_at_the_moving_average_not_the_purchase_price(): void
    {
        // Second receipt at a higher price: (100 * 15.000 + 50 * 18.000) / 150
        //   = 2.400.000 / 150 = 16.000
        $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 50, 18000]], '2026-03-05')
        );

        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15')
        );

        // 40 * 16.000 = 640.000 (not 40 * 18.000 and not 40 * 15.000)
        $lines = $this->linesByAccount($this->singleJournalFor('inventory_issue', (int) $issue->id));

        $this->assertSame(640000.0, $lines['5-1100']['debit']);
        $this->assertSame(640000.0, $lines['1-1400']['credit']);
        $this->assertSame(640000.0, (float) ProjectCost::query()->sole()->amount);
    }

    public function test_a_refused_second_posting_never_doubles_the_project_cost(): void
    {
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15')
        );

        try {
            $this->stock()->postIssue($issue->fresh());
        } catch (\LogicException) {
            // expected — the guard is asserted in the unit suite
        }

        $this->assertCount(1, Journal::query()->where('reference_type', 'inventory_issue')->get());
        $this->assertSame(600000.0, (float) ProjectCost::query()->sole()->amount);
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
    }
}
