<?php

namespace Tests\Feature\Inventory;

use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\ReportService;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * WHERE THE COST OF A SITE MATERIAL ISSUE BELONGS.
 *
 * StockService debits a 5-xxxx HPP account when an issue names a project and
 * 6-4100 Beban Umum & Administrasi when it does not, and that rule is right:
 * material nobody attributed to a project IS overhead. The defect was never the
 * rule, it was the data reaching it. DatabaseSeeder runs Inventory fifth and
 * Projects seventh, so `prj_projects` was empty when the demo's issue posted;
 * seedIssue() looked the project up by canon code, stored null, and the engine
 * then correctly classified a project's own material as office overhead.
 *
 * On the shipped database that was ISS/2026/VII/0001 — "Pengecoran kolom lantai
 * 1 zona A, Gedung Graha Sentosa", issued from that project's own site warehouse
 * — putting Rp 18.740.000 into general administration and leaving project
 * profitability for PRJ-2026-001 short of material cost by the same amount. The
 * trap is that everything reconciles: the trial balance balances and 1-1400
 * still agrees with the stock sub-ledger. Only the classification is wrong, so
 * no coherence check catches it.
 *
 * A fresh install gets this right by construction now (InventoryDatabaseSeeder
 * seeds the projects before the stock documents). These tests pin the repair for
 * installations that already exist, which is the only place it can still be got
 * wrong:
 * Modules/Inventory/Database/Migrations/2026_07_25_000496_link_project_issues_and_reclass_cost.
 *
 * Every figure is hand-computed beside its assertion, on the shipped demo's own
 * numbers: 1.000 zak received @ 62.000, 300 zak issued = 18.600.000.
 */
class ProjectIssueCostReclassTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const RECEIPT_QTY = 1000.0;

    private const UNIT_COST = 62000.0;

    /** 300 * 62.000 = 18.600.000 */
    private const ISSUE_QTY = 300.0;

    private const ISSUE_VALUE = 18600000.0;

    private Project $project;

    private Warehouse $site;

    private Warehouse $pusat;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->project = $this->makeProject();
        $this->site = $this->makeWarehouse('WH-PRJ-2026-001', ['project_id' => $this->project->id]);
        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Portland 50kg');
    }

    // ------------------------------------------------- the repair itself

    public function test_an_unattributed_issue_from_a_site_warehouse_is_linked_to_that_warehouses_project(): void
    {
        $issue = $this->postUnattributedSiteIssue();

        $this->assertNull($issue->fresh()->project_id);

        $this->runReclassMigration();

        // A site warehouse exists to serve its project: material leaving it is
        // that project's cost. This is the inference the seeder could not make
        // only because the project row did not exist yet.
        $this->assertSame((int) $this->project->id, (int) $issue->fresh()->project_id);
    }

    public function test_the_cost_is_moved_out_of_overhead_and_onto_the_projects_hpp_account(): void
    {
        $issue = $this->postUnattributedSiteIssue();

        // What the engine booked at the time: Dr 6-4100 / Cr 1-1400.
        $this->assertSame(self::ISSUE_VALUE, $this->accountNet('6-4100'));

        $this->runReclassMigration();

        // Dr 5-1100 18.600.000 / Cr 6-4100 18.600.000.
        $reclass = $this->linesByAccount(
            $this->singleJournalFor('inventory_issue_cost_reclass', (int) $issue->id)
        );

        $this->assertSame(self::ISSUE_VALUE, $reclass['5-1100']['debit']);
        $this->assertSame(self::ISSUE_VALUE, $reclass['6-4100']['credit']);

        // 18.600.000 raised as overhead + 18.600.000 reclassified = 0, and the
        // whole amount now sits in project cost of sales.
        $this->assertSame(0.0, $this->accountNet('6-4100'));
        $this->assertSame(self::ISSUE_VALUE, $this->accountNet('5-1100'));
    }

    public function test_the_original_journal_is_corrected_by_another_journal_never_rewritten(): void
    {
        $issue = $this->postUnattributedSiteIssue();

        $original = $this->singleJournalFor('inventory_issue', (int) $issue->id);

        $this->runReclassMigration();

        // A posted journal is a record of what happened: byte-for-byte intact.
        $this->assertSame(2, $original->fresh()->lines()->count());
        $this->assertSame(
            self::ISSUE_VALUE,
            $this->linesByAccount($original->fresh())['6-4100']['debit']
        );
    }

    /**
     * The DEBIT carries the project, the CREDIT does not — and that asymmetry is
     * the point, not an oversight.
     *
     * The credit reverses the original 6-4100 debit, and that debit was posted
     * with no project (an issue that named no project is exactly why this
     * reclass exists). Tagging the reversal with the project too would net the
     * project-tagged P&L movement of the whole entry to zero: the project would
     * gain nothing from the reclass, and 6-4100 would be left carrying a
     * project-tagged CREDIT — a negative operating expense, which reads as
     * income against that project.
     *
     * Measured on the shipped demo before this was corrected:
     * profitLoss(2026, project 1) reported operating_expenses -18.740.000 while
     * projectProfitability(1) reported the same cost correctly at 228.240.000.
     * The two reports the UI shows side by side disagreed.
     */
    public function test_the_reclass_tags_the_project_on_the_debit_only(): void
    {
        $issue = $this->postUnattributedSiteIssue();

        $this->runReclassMigration();

        $journal = $this->singleJournalFor('inventory_issue_cost_reclass', (int) $issue->id);

        foreach ($journal->lines as $line) {
            if ((float) $line->debit > 0) {
                $this->assertSame(
                    (int) $this->project->id,
                    (int) $line->project_id,
                    'the HPP debit must reach the project',
                );

                continue;
            }

            $this->assertNull(
                $line->project_id,
                'the reversing credit must mirror the untagged 6-4100 debit it corrects',
            );
        }
    }

    /**
     * The consequence that matters: the project's cost of sales rises by the
     * reclassified amount and its operating expenses stay at zero.
     */
    public function test_the_project_profit_and_loss_shows_the_cost_once_and_no_negative_expense(): void
    {
        $this->postUnattributedSiteIssue();

        $this->runReclassMigration();

        $report = app(ReportService::class)
            ->profitLoss('2026-01-01', '2026-12-31', (int) $this->project->id);

        $this->assertSame(self::ISSUE_VALUE, $report['cogs']['total']);
        $this->assertSame(0.0, $report['operating_expenses']['total']);
    }

    public function test_the_realisasi_missing_from_the_cost_ledger_is_recorded(): void
    {
        $issue = $this->postUnattributedSiteIssue();

        // Nothing was recorded when it posted: StockService skips the cost
        // ledger entirely for an issue with no project.
        $this->assertSame(0, ProjectCost::query()->count());

        $this->runReclassMigration();

        $cost = ProjectCost::query()
            ->where('reference_type', 'inventory_issue')
            ->where('reference_id', $issue->id)
            ->sole();

        $this->assertSame((int) $this->project->id, (int) $cost->project_id);
        $this->assertSame('material', $cost->cost_category->value);
        $this->assertSame(self::ISSUE_VALUE, round((float) $cost->amount, 2));

        // Dated on the issue, not on the day the repair happened to run.
        $this->assertSame('2026-07-05', $cost->cost_date->toDateString());

        // Project profitability now sees the material it always consumed.
        $this->assertSame(
            self::ISSUE_VALUE,
            $this->projectCosts()->totalsByCategory((int) $this->project->id)['material']
        );
    }

    public function test_the_repair_leaves_the_books_balanced_and_agreeing_with_the_stock_sub_ledger(): void
    {
        $this->postUnattributedSiteIssue();

        $this->runReclassMigration();

        // Reclassifying moves cost between two expense accounts: it can change
        // neither the stock balance nor the totals.
        $this->assertSame(
            $this->balanceValue($this->site, $this->semen),
            $this->accountNet('1-1400')
        );

        $this->assertTrue($this->reports()->trialBalance(2026, 7)['balanced']);
        $this->assertTrue($this->reports()->balanceSheet('2026-07-31')['balanced']);
    }

    // ------------------------------------------------- idempotency

    public function test_running_the_repair_twice_books_nothing_a_second_time(): void
    {
        $this->postUnattributedSiteIssue();

        $this->runReclassMigration();

        $afterFirst = Journal::query()->count();

        $this->runReclassMigration();
        $this->runReclassMigration();

        // A redeploy must not double the HPP, nor credit overhead into a
        // negative balance.
        $this->assertSame($afterFirst, Journal::query()->count());
        $this->assertSame(1, ProjectCost::query()->count());
        $this->assertSame(0.0, $this->accountNet('6-4100'));
        $this->assertSame(self::ISSUE_VALUE, $this->accountNet('5-1100'));
    }

    // ------------------------------------------------- what is NOT repaired

    public function test_an_issue_from_a_warehouse_with_no_project_stays_in_overhead(): void
    {
        // A central store serves the company, not one project. Material leaving
        // it with no project named is genuinely unattributed, and guessing a
        // project for it would invent cost the site never consumed.
        $this->receiveStock($this->pusat, $this->semen, self::RECEIPT_QTY, self::UNIT_COST, '2026-07-01');

        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, self::ISSUE_QTY]], null, '2026-07-05')
        );

        $this->runReclassMigration();

        $this->assertNull($issue->fresh()->project_id);
        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue_cost_reclass')->count());
        $this->assertSame(self::ISSUE_VALUE, $this->accountNet('6-4100'));
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_an_issue_that_already_names_a_project_is_left_alone(): void
    {
        // The engine already debited 5-1100 and recorded the realisasi; there is
        // nothing sitting in overhead to move.
        $this->receiveStock($this->site, $this->semen, self::RECEIPT_QTY, self::UNIT_COST, '2026-07-01');

        $this->stock()->postIssue($this->makeIssue(
            $this->site,
            [[$this->semen, self::ISSUE_QTY]],
            (int) $this->project->id,
            '2026-07-05',
        ));

        $this->assertSame(self::ISSUE_VALUE, $this->accountNet('5-1100'));

        $this->runReclassMigration();

        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue_cost_reclass')->count());
        $this->assertSame(self::ISSUE_VALUE, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('6-4100'));
    }

    public function test_a_draft_issue_is_not_touched(): void
    {
        // Nothing has been posted, so there is no cost to classify and no
        // project link to assert. The draft is the user's to finish.
        $this->receiveStock($this->site, $this->semen, self::RECEIPT_QTY, self::UNIT_COST, '2026-07-01');

        $draft = $this->makeIssue($this->site, [[$this->semen, self::ISSUE_QTY]], null, '2026-07-05');

        $this->runReclassMigration();

        $this->assertNull($draft->fresh()->project_id);
        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue_cost_reclass')->count());
    }

    public function test_an_issue_that_never_reached_the_ledger_is_linked_but_raises_no_journal(): void
    {
        // Perpetual posting off: the sub-ledger moved, the GL never did. There
        // is nothing in overhead to reclassify, so the repair links the project
        // and stops — inventing a journal here would post cost twice once the
        // GL bootstrap migration does its own job.
        $this->receiveStock($this->site, $this->semen, self::RECEIPT_QTY, self::UNIT_COST, '2026-07-01');

        $this->setSetting('accounting.perpetual_inventory', false);

        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->site, [[$this->semen, self::ISSUE_QTY]], null, '2026-07-05')
        );

        $this->setSetting('accounting.perpetual_inventory', null);

        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue')->count());

        $this->runReclassMigration();

        $this->assertSame((int) $this->project->id, (int) $issue->fresh()->project_id);
        $this->assertSame(0, Journal::query()->where('reference_type', 'inventory_issue_cost_reclass')->count());
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('6-4100'));
    }

    // ------------------------------------------------- helpers

    /**
     * The shipped defect, reproduced exactly: stock on hand at the site, then an
     * issue posted while the project row does not exist yet, so StockService
     * debits 6-4100.
     */
    private function postUnattributedSiteIssue(): Issue
    {
        $this->receiveStock($this->site, $this->semen, self::RECEIPT_QTY, self::UNIT_COST, '2026-07-01');

        return $this->stock()->postIssue(
            $this->makeIssue($this->site, [[$this->semen, self::ISSUE_QTY]], null, '2026-07-05')
        );
    }

    /**
     * Run the Inventory data migration that attributes site issues to their
     * project. `require` (not require_once) so each call returns a fresh
     * instance, which is what makes the idempotency checks above meaningful.
     * Located by glob so renumbering the migration cannot silently skip this
     * test.
     */
    private function runReclassMigration(): void
    {
        $files = glob(base_path(
            'Modules/Inventory/Database/Migrations/*_link_project_issues_and_reclass_cost.php'
        ));

        $this->assertIsArray($files);
        $this->assertCount(1, $files, 'The project-issue reclassification migration is missing.');

        $migration = require $files[0];

        $migration->up();
    }

    /**
     * Signed movement of a COA account across every posted journal line:
     * debit - credit. Zero means the account has been fully cleared.
     */
    private function accountNet(string $code): float
    {
        $sums = JournalLine::query()
            ->where('account_id', $this->accountId($code))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return round((float) $sums->debit - (float) $sums->credit, 2);
    }
}
