<?php

namespace Tests\Feature\Inventory;

use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\IssueReturn;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\IssueReturnService;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Retur material dari proyek (temuan 37) — the PARTIAL way back for a posted
 * bon, where cancelIssue() is the whole-document one.
 *
 * The audit's case: 150 zak issued, the pekerjaan finishes, 30 come back. The
 * two documents that could receive them were both lies — a vendor-less GRN
 * credits EQUITY 3-3100 (stock nobody bought), an opname credits EXPENSE
 * 6-4400 (stock that was lost) — and neither touched fin_project_costs, so the
 * project P&L kept carrying material that was back on the shelf.
 *
 * The one rule everything here hangs off: goods come back at the price they
 * LEFT at (the issue line's frozen unit_cost), never at today's average, so
 * the slice of cost leaving the project is the slice once booked.
 */
class IssueReturnTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    /** Cross-module id: Projects owns prj_projects, there is no FK to satisfy. */
    private const PROJECT_ID = 77;

    private const REASON = 'Sisa material pekerjaan struktur dikembalikan ke gudang.';

    private Warehouse $pusat;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');

        // 100 zak @ 15.000 = 1.500.000 of persediaan to work with.
        $this->receiveStock($this->pusat, $this->semen, 100, 15000, '2026-03-01');
    }

    private function returns(): IssueReturnService
    {
        return app(IssueReturnService::class);
    }

    /** A posted bon of 40 zak @ 15.000 = 600.000 against the project. */
    private function postedIssue(?int $projectId = self::PROJECT_ID, string $date = '2026-03-15'): Issue
    {
        return $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], $projectId, $date)
        );
    }

    /** A draft retur of $qty zak against the bon's single line. */
    private function draftReturn(Issue $issue, float $qty, string $date = '2026-03-25'): IssueReturn
    {
        return $this->returns()->create([
            'issue_id' => $issue->id,
            'return_date' => $date,
            'returned_by' => $this->warehouseUser()->id,
            'reason' => self::REASON,
            'items' => [
                ['issue_item_id' => (int) $issue->items()->sole()->id, 'qty' => $qty],
            ],
        ]);
    }

    // ------------------------------------------------------------------- works

    public function test_posting_a_partial_return_puts_the_slice_back_at_the_issue_price(): void
    {
        $issue = $this->postedIssue();
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));

        $return = $this->stock()->postIssueReturn($this->draftReturn($issue, 10));

        $this->assertSame(StockDocumentStatus::Posted, $return->status);

        // 10 of the 40 zak back at the 15.000 they left at.
        $this->assertSame(70.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));

        $line = $return->items()->sole();
        $this->assertSame(15000.0, (float) $line->unit_cost);
        $this->assertSame(150000.0, (float) $line->amount);

        // Append-only kartu stok: receipt in, issue out, return in.
        $rows = $this->ledgerFor($this->pusat, $this->semen);
        $this->assertSame(['in', 'out', 'in'], $rows->pluck('direction')->all());
        $this->assertSame(15000.0, (float) $rows->last()->unit_cost);
        $this->assertSame(70.0, (float) $rows->last()->balance_qty_after);

        // The bon itself is untouched — still posted, still 40 zak.
        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
        $this->assertSame(40.0, (float) $issue->items()->sole()->qty);
    }

    public function test_the_journal_reverses_exactly_the_cost_slice(): void
    {
        $issue = $this->postedIssue();
        $return = $this->stock()->postIssueReturn($this->draftReturn($issue, 10));

        $journal = $this->singleJournalFor('inventory_issue_return', (int) $return->id);
        $this->assertPostedAndBalanced($journal, '2026-03-25');

        $lines = $this->linesByAccount($journal);

        // Dr 1-1400 / Cr 5-1100, both carrying the project — the mirror of the
        // slice postIssue() booked, 10 zak * 15.000.
        $this->assertSame(150000.0, $lines['1-1400']['debit']);
        $this->assertSame(150000.0, $lines['5-1100']['credit']);
        $this->assertSame(self::PROJECT_ID, $lines['5-1100']['project_id']);
        $this->assertArrayNotHasKey('6-4400', $lines);

        // Project cost after: 600.000 - 150.000. GL and cost ledger agree.
        $this->assertSame(450000.0, $this->accountNet('5-1100'));
        $this->assertSame(450000.0, round((float) ProjectCost::query()->sum('amount'), 2));

        // The negative row is a NEW row keyed to the return line — the bon's
        // own rows are never edited (forward-only).
        $negative = ProjectCost::query()->where('reference_type', 'inventory_issue_return_item')->sole();
        $this->assertSame(-150000.0, (float) $negative->amount);
        $this->assertSame('material', $negative->cost_category->value);
        $this->assertSame(self::PROJECT_ID, (int) $negative->project_id);
    }

    public function test_the_stock_comes_back_at_the_issue_price_not_todays_average(): void
    {
        // The average has moved since the bon: 60 @ 15.000 left + 60 @ 25.000
        // new = 120 @ 20.000. A return valued at TODAY's average would hand the
        // project back 200.000 for 10 zak it was only ever charged 150.000 for.
        $issue = $this->postedIssue();
        $this->receiveStock($this->pusat, $this->semen, 60, 25000, '2026-03-20');

        $this->assertSame(20000.0, $this->balanceAvg($this->pusat, $this->semen));

        $return = $this->stock()->postIssueReturn($this->draftReturn($issue, 10));

        // (120 * 20.000 + 10 * 15.000) / 130 = 19.615,38 — the 15.000 entry
        // re-averages, exactly like any other stock-in.
        $this->assertSame(130.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(19615.38, $this->balanceAvg($this->pusat, $this->semen));

        $lines = $this->linesByAccount($this->singleJournalFor('inventory_issue_return', (int) $return->id));

        // Costing rule 2: the 1-1400 leg is what the stored balance actually
        // gained (130 * 19.615,38 - 120 * 20.000 = 149.999,40), the 5-1100
        // credit is the frozen slice, and the Rp 0,60 re-averaging gap lands in
        // 6-4400 — postCancellationRoundingJournal()'s shape, per document.
        $this->assertSame(149999.40, $lines['1-1400']['debit']);
        $this->assertSame(150000.0, $lines['5-1100']['credit']);
        $this->assertSame(0.60, $lines['6-4400']['debit']);

        // The identity two other tests and the CLI health check assert outright.
        $this->assertSame($this->subLedgerValue(), $this->accountNet('1-1400'));
        // And the project got back the full slice, not the drifted value.
        $this->assertSame(450000.0, $this->accountNet('5-1100'));
    }

    public function test_a_bon_without_a_project_credits_general_opex(): void
    {
        $issue = $this->postedIssue(null);
        $return = $this->stock()->postIssueReturn($this->draftReturn($issue, 10));

        $lines = $this->linesByAccount($this->singleJournalFor('inventory_issue_return', (int) $return->id));

        // The mirror of postIssue()'s own choice for a no-project bon: 6-4100,
        // not a 5-xxxx HPP account — and no project cost row to reverse.
        $this->assertSame(150000.0, $lines['6-4100']['credit']);
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_returns_are_capped_cumulatively_at_the_issued_quantity(): void
    {
        $issue = $this->postedIssue();

        $this->stock()->postIssueReturn($this->draftReturn($issue, 30));
        $this->assertSame(90.0, $this->balanceQty($this->pusat, $this->semen));

        // 30 already back; 20 more would make 50 out of a 40-zak bon.
        $second = $this->draftReturn($issue, 20, '2026-03-26');

        try {
            $this->stock()->postIssueReturn($second);
            $this->fail('Expected the cumulative ceiling to refuse the second return.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah kembali lewat retur sebelumnya', $e->getMessage());
        }

        // Nothing moved: the refusal rolled the whole posting back, so the
        // cost ledger still reads 600.000 - 450.000 from the first return.
        $this->assertSame(90.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(StockDocumentStatus::Draft, $second->fresh()->status);
        $this->assertSame(150000.0, round((float) ProjectCost::query()->sum('amount'), 2));
    }

    public function test_a_draft_cannot_reference_the_same_issue_line_twice(): void
    {
        // The duplicate-line bypass: qtyReturned() counts POSTED documents
        // only, so two 30-zak lines of ONE draft each fit alone under a 40-zak
        // bon — posted together they walk 60 back, 20 of them phantom.
        $issue = $this->postedIssue();
        $lineId = (int) $issue->items()->sole()->id;

        try {
            $this->returns()->create([
                'issue_id' => $issue->id,
                'return_date' => '2026-03-25',
                'reason' => self::REASON,
                'items' => [
                    ['issue_item_id' => $lineId, 'qty' => 30],
                    ['issue_item_id' => $lineId, 'qty' => 30],
                ],
            ]);
            $this->fail('Expected a duplicate issue_item_id to be refused at drafting.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('dua kali', $e->getMessage());
        }

        $this->assertSame(0, IssueReturn::query()->count());

        // The wire door says the same thing: the request refuses the payload
        // before the service is even asked.
        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/inventory/issue-returns', [
                'issue_id' => $issue->id,
                'return_date' => '2026-03-25',
                'reason' => self::REASON,
                'items' => [
                    ['issue_item_id' => $lineId, 'qty' => 30],
                    ['issue_item_id' => $lineId, 'qty' => 30],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.issue_item_id']);
    }

    public function test_the_posting_ceiling_counts_sibling_lines_of_one_document(): void
    {
        // Belt and braces: a document crafted before the drafting guard
        // existed can still be sitting in inv_issue_return_items, so the
        // posting loop must hold on its own. Without the sibling term each
        // 30-zak line passes alone (30 <= 40), 60 zak walk back onto the
        // shelf, and 5-1100 plus fin_project_costs are driven NEGATIVE by
        // 20 * 15.000 the project never spent.
        $issue = $this->postedIssue();
        $return = $this->draftReturn($issue, 30);

        // The sibling line injected past syncItems, as a pre-guard draft.
        $return->items()->create([
            'issue_item_id' => (int) $issue->items()->sole()->id,
            'item_id' => $this->semen->id,
            'qty' => 30,
            'unit_cost' => 0,
            'amount' => 0,
        ]);

        try {
            $this->stock()->postIssueReturn($return);
            $this->fail('Expected the ceiling to count the sibling line of the same document.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah kembali lewat retur', $e->getMessage());
        }

        // The refusal rolled the whole posting back: shelf still 60, the bon's
        // full 600.000 still on the project, no journal, the draft untouched.
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(StockDocumentStatus::Draft, $return->fresh()->status);
        $this->assertSame(600000.0, round((float) ProjectCost::query()->sum('amount'), 2));
        $this->assertSame(0, DB::table('fin_journals')->where('reference_type', 'inventory_issue_return')->count());
    }

    public function test_one_document_cannot_return_more_than_was_issued(): void
    {
        $issue = $this->postedIssue();

        try {
            $this->stock()->postIssueReturn($this->draftReturn($issue, 50));
            $this->fail('Expected a 50-zak return against a 40-zak bon to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('hanya mengeluarkan', $e->getMessage());
        }

        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, DB::table('fin_journals')->where('reference_type', 'inventory_issue_return')->count());
    }

    public function test_a_bon_with_a_posted_return_can_no_longer_be_cancelled_whole(): void
    {
        // cancelIssue() mirrors the FULL original: on top of a posted 10-zak
        // retur it would restore 50 zak for a bon that only ever took 40, and
        // credit 5-1100 by cost the project no longer carries.
        $issue = $this->postedIssue();
        $this->stock()->postIssueReturn($this->draftReturn($issue, 10));

        try {
            $this->stock()->cancelIssue($issue->fresh(), 'Bon salah proyek.', $this->warehouseUser()->id);
            $this->fail('Expected cancellation over a posted return to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('retur material', $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
        $this->assertSame(70.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertNoJournalFor('inventory_issue_cancellation', (int) $issue->id);
    }

    public function test_a_field_report_bon_cannot_be_returned(): void
    {
        // Same reason cancelIssue() refuses: the pengesahan and the parts
        // leaving are one event, and the report is the document to correct.
        $issue = $this->postedIssue();
        DB::table('inv_issues')->where('id', $issue->id)->update(['field_report_id' => 4242]);

        try {
            $this->draftReturn($issue->fresh(), 10);
            $this->fail('Expected a field-report bon to be refused at drafting.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('laporan lapangan', $e->getMessage());
        }

        $this->assertSame(0, IssueReturn::query()->count());
    }

    public function test_a_draft_made_before_the_bon_became_a_field_report_is_still_refused_at_posting(): void
    {
        // The drafting guard alone is not the protection — the posting re-asks
        // inside its transaction, because the flag can land in between.
        $issue = $this->postedIssue();
        $return = $this->draftReturn($issue, 10);
        DB::table('inv_issues')->where('id', $issue->id)->update(['field_report_id' => 4242]);

        try {
            $this->stock()->postIssueReturn($return);
            $this->fail('Expected the posting to re-check the field-report flag.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('laporan lapangan', $e->getMessage());
        }

        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_only_a_posted_bon_can_be_returned(): void
    {
        $draft = $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15');

        try {
            $this->draftReturn($draft, 10);
            $this->fail('Expected a draft bon to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('bon yang sudah diposting', $e->getMessage());
        }

        // A cancelled bon has already been returned in full by its own mirror.
        $cancelled = $this->postedIssue();
        $this->stock()->cancelIssue($cancelled, 'Bon salah gudang.', $this->warehouseUser()->id);

        try {
            $this->draftReturn($cancelled->fresh(), 10);
            $this->fail('Expected a cancelled bon to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('bon yang sudah diposting', $e->getMessage());
        }
    }

    public function test_a_return_cannot_be_posted_twice(): void
    {
        $issue = $this->postedIssue();
        $return = $this->stock()->postIssueReturn($this->draftReturn($issue, 10));

        try {
            $this->stock()->postIssueReturn($return->fresh());
            $this->fail('Expected a second posting to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('berstatus posted', $e->getMessage());
        }

        // 10 zak came back exactly once, not 20.
        $this->assertSame(70.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(450000.0, round((float) ProjectCost::query()->sum('amount'), 2));
    }

    public function test_the_period_gate_and_the_chronology_guard_apply_to_the_return_date(): void
    {
        $issue = $this->postedIssue();

        // Costing rule 3: a closed March refuses a March-dated return.
        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'closed']);

        try {
            $this->stock()->postIssueReturn($this->draftReturn($issue, 10));
            $this->fail('Expected the fiscal-period gate to refuse the return.');
        } catch (DomainException|LogicException $e) {
            $this->assertStringContainsString('2026-03', $e->getMessage());
        }

        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'open']);

        // Costing rule 1: a movement already recorded AFTER the return date
        // refuses it — same wording every back-dated document gets.
        $this->receiveStock($this->pusat, $this->semen, 20, 15000, '2026-04-02');

        try {
            $this->stock()->postIssueReturn($this->draftReturn($issue, 10, '2026-03-25'));
            $this->fail('Expected the chronology guard to refuse the back-dated return.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('lebih awal dari mutasi terakhir', $e->getMessage());
        }

        $this->assertSame(80.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_free_issue_slice_returns_its_stock_without_a_journal(): void
    {
        $gratis = $this->makeItem('Sampel Cat Tembok', ['unit' => 'kaleng']);
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$gratis, 10, 0]], '2026-03-01'));

        $issue = $this->stock()->postIssue($this->makeIssue($this->pusat, [[$gratis, 4]], null, '2026-03-15'));

        $return = $this->stock()->postIssueReturn($this->returns()->create([
            'issue_id' => $issue->id,
            'return_date' => '2026-03-25',
            'reason' => self::REASON,
            'items' => [['issue_item_id' => (int) $issue->items()->sole()->id, 'qty' => 2]],
        ]));

        $this->assertSame(StockDocumentStatus::Posted, $return->fresh()->status);
        $this->assertSame(8.0, $this->balanceQty($this->pusat, $gratis));
        $this->assertNoJournalFor('inventory_issue_return', (int) $return->id);
    }

    // ---------------------------------------------------------------- endpoint

    public function test_the_detail_action_drafts_the_remaining_returnable_quantities(): void
    {
        $issue = $this->postedIssue();
        $this->stock()->postIssueReturn($this->draftReturn($issue, 30));

        // 40 issued, 30 back: the one-click draft offers exactly the 10 left.
        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/issues/{$issue->id}/returns", ['reason' => self::REASON])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.issue_id', $issue->id);

        $draft = IssueReturn::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(10.0, (float) $draft->items()->sole()->qty);

        // And once everything is back, the action says so instead of drafting
        // an empty document.
        $this->stock()->postIssueReturn($draft);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/issues/{$issue->id}/returns", ['reason' => self::REASON])
            ->assertStatus(422);
    }

    public function test_the_endpoint_posts_a_draft_and_reports_the_result(): void
    {
        $issue = $this->postedIssue();
        $return = $this->draftReturn($issue, 10);
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/issue-returns/{$return->id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(70.0, $this->balanceQty($this->pusat, $this->semen));

        // The reason is the audit trail, so the endpoint refuses one too short
        // to tell an auditor anything — same floor as a cancellation.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/issues/{$issue->id}/returns", ['reason' => 'sisa'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    // ------------------------------------------------------------------ helper

    /**
     * Posted debit minus credit on one COA code — how the GL is read back.
     */
    private function accountNet(string $code): float
    {
        $row = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', $code)
            ->whereNull('fin_journals.deleted_at')
            ->where('fin_journals.status', 'posted')
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) - COALESCE(SUM(fin_journal_lines.credit), 0) AS net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }

    /**
     * The whole stock sub-ledger, sum(qty * avg_cost) — the figure GL 1-1400
     * equals by construction (costing rule 2).
     */
    private function subLedgerValue(): float
    {
        return round((float) StockBalance::query()->selectRaw('COALESCE(SUM(qty * avg_cost), 0) AS v')->value('v'), 2);
    }
}
