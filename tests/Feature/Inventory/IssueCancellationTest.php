<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\JournalService;
use Modules\Finance\Services\ProjectCostService;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * THE WAY BACK for the one stock document that lands on project cost.
 *
 * A posted bon used to be permanent. ISS/2026/VII/0001 issues Rp 18.740.000 of
 * semen and besi; posted against PRJ-2026-001 when the material went to
 * PRJ-2026-002 there was nothing to do about it — an opname restores the
 * quantity but books the value to 6-4400 Selisih Persediaan (shrinkage, not a
 * project transfer), and a manual JV moves the GL while leaving the stock ledger
 * and fin_project_costs saying the opposite. Both projects' realisasi, CPI and
 * PSAK 115 cost base stayed wrong for good.
 *
 * Cancellation is a NEW document, never an edit: the original posting stands in
 * the ledger, a mirror movement puts the stock back at the cost it left at, and
 * a reversing journal sits beside the original — the same shape
 * ApBillService::cancel() has on the AP side, including reversalDate()'s rule
 * about which month a reversal may land in.
 */
class IssueCancellationTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    /** Cross-module id: Projects owns prj_projects, there is no FK to satisfy. */
    private const PROJECT_ID = 77;

    private const REASON = 'Bon salah proyek: material dikirim ke PRJ-2026-002.';

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

    private function postedIssue(string $date = '2026-03-15'): Issue
    {
        return $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, $date)
        );
    }

    // ------------------------------------------------------------------- works

    public function test_cancelling_a_posted_bon_puts_the_stock_back_at_the_cost_it_left_at(): void
    {
        $issue = $this->postedIssue();

        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));

        $cancelled = $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        $this->assertSame(StockDocumentStatus::Cancelled, $cancelled->status);
        $this->assertSame(self::REASON, $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($this->warehouseUser()->id, (int) $cancelled->cancelled_by);

        // 40 zak back at the 15.000 they were valued at, so the warehouse is
        // exactly where the receipt left it.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertSame(1500000.0, $this->balanceValue($this->pusat, $this->semen));

        // Append-only: receipt in, issue out, cancellation in. Nothing rewritten.
        $rows = $this->ledgerFor($this->pusat, $this->semen);
        $this->assertSame(['in', 'out', 'in'], $rows->pluck('direction')->all());
        $this->assertSame(15000.0, (float) $rows->last()->unit_cost);
        $this->assertSame(100.0, (float) $rows->last()->balance_qty_after);
    }

    public function test_cancelling_reverses_the_journal_without_touching_the_original(): void
    {
        $issue = $this->postedIssue();

        $original = $this->singleJournalFor('inventory_issue', (int) $issue->id);
        $originalLines = $this->linesByAccount($original);
        $this->assertSame(600000.0, $originalLines['5-1100']['debit']); // 40 * 15.000

        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        // The posted original is immutable and still says what it said.
        $original->refresh();
        $this->assertSame(PostingStatus::Posted, $original->status);
        $this->assertSame(600000.0, $this->linesByAccount($original)['5-1100']['debit']);

        // The reversal is a separate posted journal with the legs swapped.
        $reversal = $this->singleJournalFor('inventory_issue_cancellation', (int) $issue->id);
        $this->assertPostedAndBalanced($reversal, '2026-03-15');
        $reversalLines = $this->linesByAccount($reversal);
        $this->assertSame(600000.0, $reversalLines['5-1100']['credit']);
        $this->assertSame(600000.0, $reversalLines['1-1400']['debit']);
        // Both legs keep the project, so the project P&L nets to zero too.
        $this->assertSame(self::PROJECT_ID, $reversalLines['5-1100']['project_id']);
        $this->assertStringContainsString(self::REASON, $reversal->description);

        // 1-1400 is back to the full receipt and 5-1100 carries nothing.
        $this->assertSame(1500000.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('5-1100'));
    }

    public function test_cancelling_removes_the_project_cost_rows_the_bon_wrote(): void
    {
        $issue = $this->postedIssue();

        $this->assertSame(600000.0, round((float) ProjectCost::query()->sum('amount'), 2));

        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        // A surviving cost row would keep the project's realisasi Rp 600.000
        // above the general ledger — the exact disagreement ApBillService::cancel
        // clears its rows to avoid.
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_cancellation_in_a_measured_or_closed_month_is_dated_today_instead(): void
    {
        $issue = $this->postedIssue();

        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'closed']);

        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        // JournalService::reversalDate() decides this, so Finance and Inventory
        // can never disagree about which month a reversal may land in: March is
        // shut, so the cancellation is an event of today.
        $reversal = $this->singleJournalFor('inventory_issue_cancellation', (int) $issue->id);
        $this->assertSame(now()->toDateString(), $reversal->journal_date->toDateString());

        // The mirror stock movement carries the same date, so the kartu stok and
        // the ledger tell one story.
        $this->assertSame(
            now()->toDateString(),
            StockLedgerEntry::query()->orderByDesc('id')->firstOrFail()->trx_date->toDateString(),
        );
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_bon_that_raised_no_journal_still_cancels_its_stock(): void
    {
        // Free-issue stock values at zero, so postIssue raised no journal and
        // JournalService::reverseFor would rightly refuse "nothing to reverse".
        $gratis = $this->makeItem('Sampel Cat Tembok', ['unit' => 'kaleng']);
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$gratis, 10, 0]], '2026-03-01'));

        $issue = $this->stock()->postIssue($this->makeIssue($this->pusat, [[$gratis, 4]], null, '2026-03-15'));
        $this->assertNoJournalFor('inventory_issue', (int) $issue->id);

        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        $this->assertSame(StockDocumentStatus::Cancelled, $issue->fresh()->status);
        $this->assertSame(10.0, $this->balanceQty($this->pusat, $gratis));
        $this->assertNoJournalFor('inventory_issue_cancellation', (int) $issue->id);
    }

    public function test_a_cancellation_after_the_stock_has_moved_again_is_dated_today_and_keeps_the_card_a_running_balance(): void
    {
        // The kartu stok reads inv_stock_ledger.balance_qty_after, which is
        // written in INSERTION order, so a mirror dated back behind movements
        // already recorded makes every later row wrong by the cancelled
        // quantity and ends the card below the shelf.
        $issue = $this->postedIssue('2026-03-20');                                   // 100 -> 60
        $this->receiveStock($this->pusat, $this->semen, 50, 15000, '2026-03-22');     //  60 -> 110
        $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 10]], null, '2026-03-25')  // 110 -> 100
        );

        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        $this->assertSame(140.0, $this->balanceQty($this->pusat, $this->semen));

        // Read the way StockController::ledger serves it — trx_date, then id.
        $card = StockLedgerEntry::query()
            ->where('warehouse_id', $this->pusat->id)
            ->where('item_id', $this->semen->id)
            ->orderBy('trx_date')->orderBy('id')
            ->get();

        $this->assertSame(
            [100.0, 60.0, 110.0, 100.0, 140.0],
            $card->map(fn (StockLedgerEntry $row): float => (float) $row->balance_qty_after)->all(),
        );
        // The card ends where the shelf is, and the last row is dated on the
        // day the cancellation happened rather than on the bon's own date.
        $this->assertSame(140.0, (float) $card->last()->balance_qty_after);
        $this->assertSame(now()->toDateString(), $card->last()->trx_date->toDateString());
        $this->assertSame(now()->toDateString(), $this->singleJournalFor('inventory_issue_cancellation', (int) $issue->id)->journal_date->toDateString());
    }

    public function test_the_gl_moves_by_exactly_what_the_stored_balance_moved(): void
    {
        // Costing rule 2. The mirror re-averages a balance a later receipt has
        // moved, so putting 777 back at the frozen 33.333,33 does NOT change the
        // sub-ledger by the Rp 25.899.997,41 the original journal took out. The
        // gap is a stock valuation difference and is booked as one; leaving it
        // in 1-1400 raised erp:inventory-method-check's tie-out blocker with no
        // document behind it, because the cancellation IS the document.
        $pasir = $this->makeItem('Pasir Beton Cor', ['unit' => 'm3']);

        $this->receiveStock($this->pusat, $pasir, 1000, 33333.33, '2026-03-01');
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$pasir, 777]], self::PROJECT_ID, '2026-03-05')
        );
        $this->receiveStock($this->pusat, $pasir, 999, 77777.77, '2026-03-10');

        $this->assertSame($this->subLedgerValue(), $this->accountNet('1-1400'));

        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        // Exactly, not approximately: this identity is what two other tests and
        // the CLI health check assert outright.
        $this->assertSame(111033335.56 + 1500000.0, $this->subLedgerValue());
        $this->assertSame($this->subLedgerValue(), $this->accountNet('1-1400'));

        // Rp 7,53 of average rounding — the audit's own measurement of this
        // case — named in the account stock valuation differences live in. A
        // credit, because the sub-ledger gained it: the same shape a surplus
        // opname books, and not left in persediaan or pushed into the project
        // P&L, whose cost rows the cancellation removes in full.
        $this->assertSame(-7.53, $this->accountNet('6-4400'));
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_bon_whose_cost_was_reclassified_is_unwound_through_every_journal_it_posted(): void
    {
        // The live demo's own shape: ISS/2026/VII/0001 posted while prj_projects
        // was still empty, so its value went to 6-4100 and migration 000496
        // moved it onto 5-1100 with a SECOND journal under
        // 'inventory_issue_cost_reclass'. Reversing only the bon's own journal
        // left project 1's GL P&L at Rp 228.240.000 while fin_project_costs fell
        // to Rp 209.500.000, and 6-4100 carrying a project-less credit.
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], null, '2026-03-15')
        );

        app(JournalService::class)->autoPost(
            'inventory_issue_cost_reclass',
            (int) $issue->id,
            [
                ['account_code' => '5-1100', 'debit' => 600000, 'project_id' => self::PROJECT_ID],
                ['account_code' => '6-4100', 'credit' => 600000],
            ],
            '2026-03-15',
            "Issue {$issue->code} — reklasifikasi pemakaian material ke HPP proyek",
        );

        DB::table('inv_issues')->where('id', $issue->id)->update(['project_id' => self::PROJECT_ID]);
        app(ProjectCostService::class)->record(
            self::PROJECT_ID, '2026-03-15', CostCategory::Material,
            'inventory_issue', (int) $issue->id, 'Pemakaian material', 600000,
        );

        $this->assertSame(600000.0, $this->accountNet('5-1100', self::PROJECT_ID));
        $this->assertSame(600000.0, round((float) ProjectCost::query()->sum('amount'), 2));

        $this->stock()->cancelIssue($issue->fresh(), self::REASON, $this->warehouseUser()->id);

        // Both books land back on zero together.
        $this->assertSame(0.0, $this->accountNet('5-1100', self::PROJECT_ID));
        $this->assertSame(0, ProjectCost::query()->count());
        // And nothing is left stranded in the account the reclass emptied.
        $this->assertSame(0.0, $this->accountNet('6-4100'));
        $this->assertSame(1500000.0, $this->accountNet('1-1400'));

        $this->assertSame(2, Journal::query()->where('reference_type', 'inventory_issue_cancellation')->count());
    }

    // ---------------------------------------------------------------- refusals

    public function test_a_cancellation_is_refused_while_a_movement_dated_ahead_holds_the_card_open(): void
    {
        // The mirror is a movement like any other, so assertMovementInOrder()
        // runs on it — there is no silent exemption. Today is the fallback date,
        // and paperwork keyed into a future month leaves no date that both keeps
        // the card straight and stays out of a month nobody may post into.
        $issue = $this->postedIssue('2026-03-15');
        $this->receiveStock($this->pusat, $this->semen, 20, 15000, '2026-12-20');

        try {
            $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a cancellation behind a later movement to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString("Pembatalan bon {$issue->code}", $e->getMessage());
            $this->assertStringContainsString('2026-12-20', $e->getMessage());
        }

        // 100 - 40 + 20: the mirror never ran, and neither did half of it.
        $this->assertSame(80.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
        $this->assertNoJournalFor('inventory_issue_cancellation', (int) $issue->id);
    }

    public function test_a_draft_bon_cannot_be_cancelled(): void
    {
        // A draft is deleted, not cancelled — there is no posting to reverse and
        // a cancellation would invent a stock movement that never happened.
        $draft = $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15');

        try {
            $this->stock()->cancelIssue($draft, self::REASON);
            $this->fail('Expected a draft bon to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('hanya bon yang sudah diposting yang dapat dibatalkan', $e->getMessage());
        }

        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, StockLedgerEntry::query()->where('direction', 'out')->count());
    }

    public function test_a_bon_cannot_be_cancelled_twice(): void
    {
        $issue = $this->postedIssue();

        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        try {
            $this->stock()->cancelIssue($issue->fresh(), self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a second cancellation to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('berstatus cancelled', $e->getMessage());
        }

        // 40 zak came back exactly once, not 80.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(1, Journal::query()->where('reference_type', 'inventory_issue_cancellation')->count());
    }

    public function test_a_cancellation_reason_is_required(): void
    {
        $issue = $this->postedIssue();

        try {
            $this->stock()->cancelIssue($issue, '   ');
            $this->fail('Expected a blank reason to be refused.');
        } catch (LogicException $e) {
            $this->assertSame('Alasan pembatalan wajib diisi.', $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_bon_raised_by_a_field_report_acknowledgement_cannot_be_cancelled_on_its_own(): void
    {
        // inv_issues.field_report_id is UNIQUE, so unwinding the bon on its own
        // would leave svc_field_reports reading "disahkan pelanggan" over a visit
        // whose parts were back on the shelf and could never be issued again.
        $issue = $this->postedIssue();
        DB::table('inv_issues')->where('id', $issue->id)->update(['field_report_id' => 4242]);

        try {
            $this->stock()->cancelIssue($issue->fresh(), self::REASON);
            $this->fail('Expected a field-report bon to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('laporan lapangan', $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_cancelled_bon_can_no_longer_be_edited_or_posted_again(): void
    {
        $issue = $this->postedIssue();
        $this->stock()->cancelIssue($issue, self::REASON, $this->warehouseUser()->id);

        try {
            $this->stock()->postIssue($issue->fresh());
            $this->fail('Expected a cancelled bon to be unpostable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only draft issues can be posted', $e->getMessage());
        }

        $this->assertFalse($issue->fresh()->status->isEditable());
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
    }

    // ---------------------------------------------------------------- endpoint

    public function test_the_endpoint_refuses_a_reason_too_short_to_tell_an_auditor_anything(): void
    {
        $issue = $this->postedIssue();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/inventory/issues/{$issue->id}/cancel", ['reason' => 'oops'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
    }

    public function test_the_endpoint_cancels_and_answers_with_the_cancelled_bon(): void
    {
        $issue = $this->postedIssue();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/inventory/issues/{$issue->id}/cancel", ['reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', self::REASON);

        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    // ----------------------------------------------------------- layar (SPA)

    /**
     * REACHABILITY, not rendering. The endpoint above is worth nothing while no
     * button calls it, and that is exactly how this feature first shipped: the
     * route was live on inv.post and 'inventory/issues' carried a single 'post'
     * action, so ISS/2026/VII/0001 could still only be cancelled with curl. A
     * finished screen nobody can open has been released here before.
     *
     * schema.js has no build step and this host has no JS runtime, so it is read
     * the way a reviewer reads it — the same grep NavRouteRegistryTest uses.
     */
    public function test_the_bon_screen_offers_the_cancellation_the_endpoint_serves(): void
    {
        $action = $this->schemaAction('inventory/issues', 'cancel');

        // Hak POSTING, bukan hak hapus — sama dengan pembatalan AR/AP, dan sama
        // dengan middleware rutenya. Kalau keduanya berbeda, tombolnya muncul
        // untuk orang yang akan ditolak 403 atau disembunyikan dari orang yang
        // sebenarnya berwenang.
        $this->assertStringContainsString("perm: 'inv.post'", $action);
        $this->assertStringContainsString("method: 'POST'", $action);
        $this->assertStringContainsString("variant: 'danger'", $action);

        // The two refusals cancelIssue() makes, mirrored in the predicate so the
        // operator is never handed a button whose only possible answer is an
        // error: a draft or already-cancelled bon, and a bon raised by a field
        // report acknowledgement.
        $this->assertStringContainsString("row.status === 'posted'", $action);
        $this->assertStringContainsString('!row.field_report_id', $action);

        // A prompt that did not demand the reason would post a blank one and be
        // refused by IssueCancelRequest with a 422 the dialog cannot fix.
        $this->assertStringContainsString("key: 'reason'", $action);
        $this->assertStringContainsString('required: true', $action);
    }

    /**
     * The URL actions.js builds out of `api` + `path` — assembled here from the
     * file, not hand-written — reaches the route that actually cancels. A typo
     * in either half is invisible until an operator clicks it in production.
     */
    public function test_the_url_the_button_builds_is_the_route_that_cancels(): void
    {
        $issue = $this->postedIssue();

        // runAction(): `${def.api}/${action.path.replace('{id}', row.id)}`,
        // under api.js's BASE of /api.
        $url = '/api/'
            .$this->schemaValue($this->schemaResource('inventory/issues'), 'api').'/'
            .str_replace('{id}', (string) $issue->id, $this->schemaValue($this->schemaAction('inventory/issues', 'cancel'), 'path'));

        $this->assertSame("/api/inventory/issues/{$issue->id}/cancel", $url);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson($url, ['reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
    }

    /**
     * The refused half of the reader: it has to be able to say NO, or every
     * assertion above would pass just as happily against a file that never
     * mentions the action. Transfers and opnames genuinely have no way back —
     * StockDocumentStatus's own note says so, and why — so their entries are
     * the honest negative control, and the day one of them gains a
     * cancellation this test is the reminder that the screen has to learn
     * about it in the same breath. Goods receipts left this list when
     * cancelReceipt() closed T37's expensive third; their button is asserted
     * in GoodsReceiptCancellationTest.
     */
    public function test_the_stock_documents_with_no_way_back_offer_no_cancel_button(): void
    {
        foreach (['inventory/transfers', 'inventory/stock-adjustments'] as $key) {
            $this->assertStringNotContainsString(
                "key: 'cancel'",
                $this->schemaResource($key),
                "RESOURCES['{$key}'] now offers a cancel button, but StockService has no cancellation for it: "
                .'the button can only ever 404 or 405. Build the service path first, then teach '
                .'enums.js stockDocStatus and this test about it.',
            );
        }
    }

    /**
     * The status filter reads enums.js, not the API, so a state missing there is
     * a state no operator can filter for: before this list gained 'cancelled' a
     * cancelled bon could not be found on the Pengeluaran Barang screen at all,
     * only scrolled past.
     */
    public function test_the_status_filter_offers_every_state_a_stock_document_can_reach(): void
    {
        $php = array_map(
            fn (StockDocumentStatus $case): array => [$case->value, $case->label()],
            StockDocumentStatus::cases(),
        );

        $this->assertSame(
            $php,
            $this->javascriptStockDocStatus(),
            'public/app/js/enums.js stockDocStatus has drifted from Modules\\Inventory\\Enums\\StockDocumentStatus. '
            .'A value only in PHP cannot be filtered for on screen; a value only in JS filters for nothing.',
        );
    }

    /** The whole RESOURCES entry for one screen, as schema.js declares it. */
    private function schemaResource(string $key): string
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($schema, "\n  '{$key}': {");

        $this->assertNotFalse($start, "RESOURCES has no '{$key}' entry; this test can no longer read that screen.");

        $tail = substr($schema, $start + 1);

        // The next entry at the same indentation ends this one. A reformat that
        // broke this would take the assertion above or the one in schemaAction()
        // with it, rather than quietly returning the rest of the file.
        $length = preg_match("/\n  '[a-z0-9\/-]+': \{/", $tail, $match, PREG_OFFSET_CAPTURE) === 1
            ? $match[0][1]
            : strlen($tail);

        return substr($tail, 0, $length);
    }

    /** One action object out of that entry's `actions` array. */
    private function schemaAction(string $resource, string $key): string
    {
        $block = $this->schemaResource($resource);
        $start = strpos($block, "key: '{$key}'");

        $this->assertNotFalse(
            $start,
            "RESOURCES['{$resource}'] declares no '{$key}' action, so no button in the SPA can reach "
            ."/api/{$resource}/{id}/{$key} — the endpoint ships as dead code.",
        );

        $end = strpos($block, "\n      },", $start);

        $this->assertNotFalse($end, "the '{$key}' action object could not be delimited; this test can no longer check it.");

        return substr($block, $start, $end - $start);
    }

    /** A `name: 'value'` string out of a schema.js fragment. */
    private function schemaValue(string $fragment, string $name): string
    {
        $this->assertSame(
            1,
            preg_match("/\\b{$name}: '([^']+)'/", $fragment, $match),
            "no {$name}: '...' in this schema.js fragment.",
        );

        return $match[1];
    }

    /** @return list<array{0: string, 1: string}> */
    private function javascriptStockDocStatus(): array
    {
        $source = (string) file_get_contents(public_path('app/js/enums.js'));

        $this->assertSame(
            1,
            preg_match('/\n  stockDocStatus: opts\(\[(.*?)\]\),/s', $source, $matches),
            'stockDocStatus could not be found in enums.js; this test can no longer check anything.',
        );

        preg_match_all("/\['([a-z_]+)', '([^']+)'\]/", $matches[1], $pairs, PREG_SET_ORDER);

        return array_map(fn (array $pair): array => [$pair[1], $pair[2]], $pairs);
    }

    // ------------------------------------------------------------------ helper

    /**
     * Posted debit minus credit on one COA code, optionally only the lines
     * tagged with a project — which is how the project P&L is read.
     */
    private function accountNet(string $code, ?int $projectId = null): float
    {
        $row = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', $code)
            ->whereNull('fin_journals.deleted_at')
            ->where('fin_journals.status', 'posted')
            ->when($projectId !== null, fn ($query) => $query->where('fin_journal_lines.project_id', $projectId))
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) - COALESCE(SUM(fin_journal_lines.credit), 0) AS net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }

    /**
     * The whole stock sub-ledger, sum(qty * avg_cost) — the figure GL 1-1400 is
     * supposed to equal by construction.
     */
    private function subLedgerValue(): float
    {
        return round((float) StockBalance::query()->selectRaw('COALESCE(SUM(qty * avg_cost), 0) AS v')->value('v'), 2);
    }
}
