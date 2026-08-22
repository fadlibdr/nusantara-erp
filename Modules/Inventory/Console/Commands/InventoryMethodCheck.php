<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Setting;
use Modules\Core\Support\Erp;
use Modules\Core\Support\Money;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Services\StockService;

/**
 * Is it safe to change the inventory accounting method right now? (audit A2)
 *
 * accounting.perpetual_inventory used to be a checkbox on the settings screen,
 * read live when a goods receipt posted and again when the vendor bill was
 * approved. One flip corrupted the ledger in whichever direction it was flipped,
 * because the two methods disagree about where the value of on-hand stock lives:
 *
 *   on at receipt, off later   the receipt debited 1-1400 and the issue that
 *       would have relieved it no longer posts, so the purchase sits in
 *       persediaan for ever against a stock sub-ledger of zero and is expensed
 *       nowhere — no 5-1100, no project realisasi.
 *   off at receipt, on later   the vendor bill already expensed the purchase to
 *       5-1100; the issue now debits 5-1100 a second time and credits a
 *       persediaan account that was never debited, so 5-1100 double counts and
 *       1-1400 goes negative.
 *
 * It is therefore an install-time constant in config/erp.php, changed only by a
 * deploy. A deploy is a deliberate act — but a deliberate act still must not
 * silently corrupt the ledger, so this command reports what a change would break
 * before it is made, and exits non-zero while anything would break.
 *
 * What it deliberately does NOT do is migrate anything. Moving between the two
 * methods requires a stock revaluation — capitalising stock that was expensed,
 * or expensing stock that was capitalised — booked at a fiscal-period boundary.
 * Which stock, at which value, against which equity or expense account, is an
 * accountant's judgement on that company's books. No command can make it.
 */
class InventoryMethodCheck extends Command
{
    protected $signature = 'erp:inventory-method-check';

    protected $description = 'Report whether changing accounting.perpetual_inventory (the inventory accounting method) is safe right now';

    private const KEY = 'accounting.perpetual_inventory';

    /** @var list<array{title: string, detail: list<string>, consequence: string}> */
    private array $blockers = [];

    public function handle(StockService $stockService): int
    {
        // The console kernel resolves a command once and reuses the instance, so
        // a second invocation in the same process would otherwise inherit the
        // first one's findings.
        $this->blockers = [];

        $configured = (bool) config('erp.'.self::KEY, true);
        $stored = $this->storedOverride();
        $effective = $stored ?? $configured;
        $target = $effective ? 'PERIODIC' : 'PERPETUAL';

        $this->line('');
        $this->line('<options=bold>Inventory accounting method — change safety check</>');
        $this->line(str_repeat('=', 64));
        $this->line(sprintf(
            'Method in force : %s   (config/erp.php: %s = %s)',
            $effective ? 'PERPETUAL' : 'PERIODIC',
            self::KEY,
            $configured ? 'true' : 'false',
        ));
        $this->line('A change would therefore move this installation to '.$target.'.');
        $this->line('');

        $this->checkStoredOverride($stored, $configured);

        if (! Schema::hasTable('inv_goods_receipts')) {
            $this->warn('Inventory tables are not migrated; there is nothing recorded that a change could strand.');

            return $this->verdict($effective);
        }

        $this->checkUnclearedReceipts();
        $this->checkPostedMovementsInOpenPeriods();
        $this->checkStockOnHand($effective, $stockService);

        return $this->verdict($effective);
    }

    /**
     * The override row for this key, if an installation stored one while it was
     * still editable, else null.
     *
     * Read straight from core_settings rather than through SettingService, for
     * the same reason invalidOverrides() does: a health check must see what is
     * STORED, not what a cache happens to hold. The override map is cached, so
     * asking the resolver would report a row that has just been deleted — which
     * is exactly the moment an operator runs this command again.
     */
    private function storedOverride(): ?bool
    {
        if (! Schema::hasTable('core_settings')) {
            return null;
        }

        $row = Setting::query()->where('key', self::KEY)->first();

        return $row === null ? null : (bool) $row->value;
    }

    /**
     * A row stored in core_settings while this key was still editable.
     *
     * The resolver still honours such a row — on purpose, because an upgrade
     * must not silently switch a company's accounting method — which means it
     * also wins over any future edit of config/erp.php. An operator who changes
     * the file while it exists changes nothing at all, and believes otherwise.
     */
    private function checkStoredOverride(?bool $stored, bool $configured): void
    {
        if ($stored === null) {
            return;
        }

        $this->blockers[] = [
            'title' => 'A stored override for '.self::KEY.' is still in force',
            'detail' => [
                sprintf(
                    'core_settings holds %s = %s; config/erp.php ships %s.',
                    self::KEY,
                    $stored ? 'true' : 'false',
                    $configured ? 'true' : 'false',
                ),
                'The key is no longer editable, so nothing can write such a row today — this one predates',
                'audit A2. It is still honoured, deliberately: an upgrade must not switch a method by itself.',
            ],
            'consequence' => 'Editing config/erp.php would have NO effect while this row exists. Decide the '
                .'method, write it into config/erp.php, then delete the core_settings row (it is also '
                .'listed by SettingService::invalidOverrides()).',
        ];
    }

    /**
     * Goods receipts whose recorded GR/IR or accrual credit no vendor bill has
     * debited back out yet — a receipt-to-invoice chain that is still open.
     *
     * Reading exactly what ApBillService reads: the amount the receipt RECORDED
     * it credited (inv_goods_receipts.gl_clearing_account / gl_clearing_amount),
     * less what non-cancelled bills recorded they cleared against the same
     * source (fin_ap_bills.gl_cleared_amount).
     */
    private function checkUnclearedReceipts(): void
    {
        if (! Schema::hasColumn('inv_goods_receipts', 'gl_clearing_account')) {
            return;
        }

        $receipts = DB::table('inv_goods_receipts')
            ->whereNull('deleted_at')
            ->where('status', StockDocumentStatus::Posted->value)
            ->whereNotNull('gl_clearing_account')
            ->where('gl_clearing_amount', '>', 0)
            ->orderBy('id')
            ->get(['id', 'code', 'purchase_order_id', 'gl_clearing_account', 'gl_clearing_amount']);

        // A bill clears either one receipt (goods_receipt_id) or every receipt of
        // a PO (purchase_order_id), so a PO's receipts have to be weighed against
        // that PO's bills as one group — exactly how ApBillService consumes them.
        $groups = [];

        foreach ($receipts as $receipt) {
            $poId = $receipt->purchase_order_id !== null ? (int) $receipt->purchase_order_id : null;
            $key = $poId !== null ? 'po:'.$poId : 'grn:'.$receipt->id;

            $groups[$key]['po_id'] ??= $poId;
            $groups[$key]['receipt_ids'][] = (int) $receipt->id;
            $groups[$key]['codes'][] = (string) $receipt->code;
            $groups[$key]['accounts'][(string) $receipt->gl_clearing_account] = true;
            $groups[$key]['recorded'] = round(
                ($groups[$key]['recorded'] ?? 0.0) + (float) $receipt->gl_clearing_amount,
                2,
            );
        }

        $rows = [];
        $overCleared = [];
        $total = 0.0;
        $overTotal = 0.0;

        foreach ($groups as $group) {
            $outstanding = round($group['recorded'] - $this->clearedAgainst($group['po_id'], $group['receipt_ids']), 2);

            if ($outstanding === 0.0) {
                continue;
            }

            $label = sprintf(
                '%s  %s',
                $group['po_id'] !== null ? 'PO #'.$group['po_id'] : 'no PO',
                implode(', ', $group['codes']),
            );

            // A NEGATIVE outstanding means bills cleared more than the receipts
            // ever credited, so the clearing account carries a balance on the
            // wrong side. Skipping it (as this check first did) hid exactly the
            // kind of defect the check exists to surface.
            if ($outstanding < 0.0) {
                $overTotal = round($overTotal + abs($outstanding), 2);
                $overCleared[] = sprintf(
                    '%s  over-cleared %s  (recorded %s)',
                    $label,
                    Money::format(abs($outstanding)),
                    implode(' + ', array_keys($group['accounts'])),
                );

                continue;
            }

            $total = round($total + $outstanding, 2);
            $rows[] = sprintf(
                '%s  outstanding %s  (recorded %s)',
                $label,
                Money::format($outstanding),
                implode(' + ', array_keys($group['accounts'])),
            );
        }

        if ($rows !== []) {
            $this->blockers[] = [
                'title' => sprintf('%d receipt(s) carry %s of clearing no bill has settled', count($rows), Money::format($total)),
                'detail' => $rows,
                'consequence' => 'These are half-finished receipt-to-invoice chains. Approve (or cancel) the '
                    .'vendor bills that clear them before changing the method, so no document straddles the '
                    .'change with its counter-entry on the other side of it.',
            ];
        }

        if ($overCleared !== []) {
            $this->blockers[] = [
                'title' => sprintf('%d receipt group(s) cleared %s MORE than was ever credited', count($overCleared), Money::format($overTotal)),
                'detail' => $overCleared,
                'consequence' => 'A clearing account is carrying a balance on the wrong side: more was billed '
                    .'against these receipts than they posted. Correct it with a journal voucher before '
                    .'changing the method — the imbalance is already in the ledger and a method change '
                    .'would bury it.',
            ];
        }
    }

    /**
     * What non-cancelled bills recorded they cleared against one group.
     *
     * @param  list<int>  $receiptIds
     */
    private function clearedAgainst(?int $poId, array $receiptIds): float
    {
        if (! Schema::hasTable('fin_ap_bills') || ! Schema::hasColumn('fin_ap_bills', 'gl_cleared_amount')) {
            // Finance is absent, so nothing can ever clear these credits.
            return 0.0;
        }

        return round((float) DB::table('fin_ap_bills')
            ->whereNull('deleted_at')
            ->where('status', '!=', DocumentStatus::Cancelled->value)
            ->where(function ($query) use ($poId, $receiptIds): void {
                $query->whereIn('goods_receipt_id', $receiptIds);

                if ($poId !== null) {
                    $query->orWhere('purchase_order_id', $poId);
                }
            })
            ->sum('gl_cleared_amount'), 2);
    }

    /**
     * Stock documents already posted inside a fiscal period that is still open.
     *
     * A method change is a period-boundary event. Posted movements in the open
     * period were accounted under the old method; movements posted after the
     * change are accounted under the new one, and the period then holds both —
     * so its inventory account no longer reconciles to its stock sub-ledger and
     * no closing entry can make it, because the two halves are not comparable.
     *
     * Issues are the ones the method decides outright (perpetual expenses them,
     * periodic does not), so they are listed first; receipts and opname
     * adjustments in the same period are the same hazard and are counted with
     * them.
     *
     * EACH TABLE BRINGS ITS OWN "is it posted" PREDICATE, because they do not
     * agree. inv_issues and inv_goods_receipts move through StockDocumentStatus
     * (draft -> posted); inv_stock_adjustments is on Core's DocumentStatus
     * lifecycle (migration 000460: `// Core DocumentStatus lifecycle`) and
     * records the ledger hit in posted_at, which is exactly what
     * StockAdjustment::isPosted() reads. DocumentStatus has no 'posted' case at
     * all, so the one shared `where('status', 'posted')` this method used to
     * apply to all three matched ZERO adjustments, always — a company whose only
     * movement in the open period was a posted 25 March opname (Dr 6-4400 /
     * Cr 1-1400) was told SAFE with exit 0, in the very state the docblock above
     * says must return 1.
     *
     * In-transit transfers are counted too: goods on the road have left one
     * warehouse and not arrived at the other, so a method change that lands
     * between send and receive strands them under two different methods.
     */
    private function checkPostedMovementsInOpenPeriods(): void
    {
        $windows = $this->openPeriodWindows();

        if ($windows === []) {
            return;
        }

        $posted = fn ($query) => $query->where('status', StockDocumentStatus::Posted->value);

        $documents = [
            ['inv_issues', 'issue_date', 'posted material issue(s)', $posted],
            ['inv_goods_receipts', 'receipt_date', 'posted goods receipt(s)', $posted],
            ['inv_stock_adjustments', 'adjustment_date', 'posted stock adjustment(s)',
                fn ($query) => $query->whereNotNull('posted_at')],
            ['inv_transfers', 'transfer_date', 'transfer(s) still in transit',
                fn ($query) => $query->where('status', TransferStatus::InTransit->value)],
        ];

        $rows = [];

        foreach ($documents as [$table, $dateColumn, $label, $isPosted]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)
                ->whereNull('deleted_at')
                ->where($isPosted)
                ->where(function ($query) use ($windows, $dateColumn): void {
                    foreach ($windows as [$start, $end]) {
                        $query->orWhere(function ($window) use ($dateColumn, $start, $end): void {
                            $window->where($dateColumn, '>=', $start)->where($dateColumn, '<', $end);
                        });
                    }
                })
                ->count();

            if ($count > 0) {
                $rows[] = sprintf('%d %s', $count, $label);
            }
        }

        if ($rows === []) {
            return;
        }

        $this->blockers[] = [
            'title' => 'Stock movements are already posted inside an open fiscal period',
            'detail' => array_merge($rows, [
                'Open period(s): '.implode(', ', array_column($windows, 2)),
            ]),
            'consequence' => 'Change the method only at a period boundary: close these periods first, so '
                .'every period is accounted end to end under one method and its inventory account '
                .'reconciles to its stock sub-ledger.',
        ];
    }

    /**
     * Stock on hand, and what the general ledger says about it.
     *
     * This is the exposure the two methods value differently, and it is the one
     * that cannot be worked off by finishing documents — it can only be revalued.
     * Under perpetual that stock is an asset in 1-1400; under periodic it is
     * already an expense and 1-1400 holds nothing.
     *
     * AND IT COMPARES THEM. This method printed both figures side by side and
     * never tested one against the other, so a sub-ledger Rp 600.000 out of step
     * with the general ledger produced the same verdict, the same blocker list
     * and the same exit code as one that agreed to the rupiah — the difference
     * was on the operator's screen, unlabelled. That is the only inventory-to-GL
     * tie-out point in the product (PeriodCloseService::itemSubledgerTied covers
     * 1-1300 and 2-1100 only), so a break that shows up here shows up nowhere
     * else at all.
     *
     * ONLY UNDER PERPETUAL, and that is not a hedge: StockService::ledgerPostingEnabled()
     * suppresses every inventory GL posting under periodic, so 1-1400 correctly
     * holds nothing there while the sub-ledger legitimately accumulates. A blanket
     * comparison would fire on every healthy periodic installation.
     *
     * Goods in transit are named as the reconciling figure rather than left for
     * the reader to guess: sendTransfer() takes stock out of the source balance
     * and receiveTransfer() puts it into the destination's, with no journal
     * between them, so for the whole transit window the sub-ledger is BELOW the
     * general ledger by exactly the value on the road. That is by design and
     * temporary; anything left over after subtracting it is not.
     */
    private function checkStockOnHand(bool $perpetual, StockService $stockService): void
    {
        if (! Schema::hasTable('inv_stock_balances')) {
            return;
        }

        $onHand = round((float) DB::table('inv_stock_balances')
            ->where('qty', '<>', 0)
            ->sum(DB::raw('qty * avg_cost')), 2);

        // The SHARED query (audit T28/T29): the Saldo Stok screen serves the
        // same StockService::inTransitValue(), so the rupiah this check names
        // and the rupiah on screen cannot drift — the query used to live only
        // in this command, which is why the screen had no figure at all.
        $inTransit = $stockService->inTransitValue();

        $inventoryCode = Erp::string('accounting.inventory_account', '1-1400');
        $ledger = $this->accountBalance($inventoryCode);

        $this->line(sprintf(
            'Stock sub-ledger : %s on hand%s%s',
            Money::format($onHand),
            $inTransit > 0.0 ? '  +  '.Money::format($inTransit).' in transit' : '',
            $ledger === null ? ' (Finance absent — no GL balance to compare)' : '  ·  GL '.$inventoryCode.' = '.Money::format($ledger),
        ));
        $this->line('');

        $this->checkSubLedgerTiesToGeneralLedger($perpetual, $onHand, $inTransit, $inventoryCode, $ledger);

        if ($onHand == 0.0 && ($ledger === null || $ledger == 0.0)) {
            return;
        }

        $this->blockers[] = [
            'title' => 'Stock on hand still carries value',
            'detail' => array_filter([
                'Stock sub-ledger: '.Money::format($onHand),
                $inTransit > 0.0 ? 'In transit (in neither warehouse balance): '.Money::format($inTransit) : null,
                $ledger === null ? null : 'General ledger '.$inventoryCode.': '.Money::format($ledger),
            ]),
            'consequence' => $perpetual
                ? 'Switching to periodic leaves this value in '.$inventoryCode.' with nothing left to '
                    .'relieve it: issues stop posting, so the material is never expensed and never reaches '
                    .'a project. It has to be expensed by a revaluation journal at the change-over date.'
                : 'Switching to perpetual makes issues credit '.$inventoryCode.', which was never debited '
                    .'for this stock — the vendor bills already expensed it. The ledger would double count '
                    .'the cost and drive '.$inventoryCode.' negative. The stock has to be capitalised by a '
                    .'revaluation journal at the change-over date.',
        ];
    }

    /**
     * The blocker this command existed to raise and never did: the stock
     * sub-ledger and the inventory control account do not agree.
     *
     * The identity, once goods on the road are put back where they belong:
     *
     *     sum(qty * avg_cost) over inv_stock_balances
     *   + value of every in-transit transfer line
     *   = net posted movement on 1-1400
     *
     * TOLERANCE, stated rather than assumed. Both sides are cents, but the
     * sub-ledger side is a sum of qty(3 dp) * avg_cost(2 dp) products, so a
     * fractional quantity can leave up to half a cent per balance row that no
     * cents-based ledger can carry. One SEN (Rp 0,01) of slack per balance row
     * absorbs that — twice the worst case per row — with a floor of one sen so
     * an install with no balance rows still gets the rounding of the in-transit
     * side. On the live dataset's 9 balance rows that is Rp 0,09 — far below
     * anything a real break produces (the probes here run to hundreds of
     * thousands). Whole-unit quantities tie exactly, because applyIn/applyOut
     * now hand the journals the movement they made on the stored balance rather
     * than a value re-derived from the document.
     */
    private function checkSubLedgerTiesToGeneralLedger(
        bool $perpetual,
        float $onHand,
        float $inTransit,
        string $inventoryCode,
        ?float $ledger,
    ): void {
        if (! $perpetual || $ledger === null) {
            return;
        }

        $owned = round($onHand + $inTransit, 2);
        $difference = round($owned - $ledger, 2);
        $tolerance = max(0.01, round(0.01 * DB::table('inv_stock_balances')->count(), 2));

        if (abs($difference) <= $tolerance) {
            return;
        }

        $this->blockers[] = [
            'title' => sprintf(
                'The stock sub-ledger and GL %s disagree by %s',
                $inventoryCode,
                Money::format(abs($difference)),
            ),
            'detail' => array_filter([
                'Stock on hand (sum of qty * avg_cost): '.Money::format($onHand),
                $inTransit > 0.0 ? 'Plus goods in transit, in neither warehouse balance: '.Money::format($inTransit) : null,
                'Total stock owned: '.Money::format($owned),
                'General ledger '.$inventoryCode.': '.Money::format($ledger),
                sprintf(
                    'Difference: %s (%s)',
                    Money::format($difference),
                    $difference > 0 ? 'sub-ledger higher — value the GL never received' : 'GL higher — value with no stock behind it',
                ),
            ]),
            'consequence' => 'This is a break, not a method question, and it exists right now under the '
                .'method already in force. A change would bury it: the revaluation journal is computed from '
                .'one of these two figures and the other would silently absorb the difference for ever. Find '
                .'the document behind it — a stock movement posted without its journal, or a journal on '
                .$inventoryCode.' with no stock movement behind it — and correct that before going near '
                .self::KEY.'.',
        ];
    }

    /**
     * Net movement on one account code across posted journals, or null when
     * Finance is not installed.
     */
    private function accountBalance(string $code): ?float
    {
        if (! Schema::hasTable('fin_journal_lines') || ! Schema::hasTable('fin_accounts')) {
            return null;
        }

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
     * Half-open [start, end) date windows of every open fiscal period, with a
     * label. Half-open rather than BETWEEN because a date column may hold a
     * datetime, and '2026-07-31 00:00:00' is not BETWEEN '…-01' and '…-31'.
     *
     * With Finance absent there are no periods to reason about, so the current
     * calendar year stands in: a change made mid-year is the hazard either way.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function openPeriodWindows(): array
    {
        if (! Schema::hasTable('fin_fiscal_periods')) {
            $year = (int) now()->year;

            return [[$year.'-01-01', ($year + 1).'-01-01', $year.' (no fiscal calendar)']];
        }

        $windows = [];

        $periods = DB::table('fin_fiscal_periods')
            ->where('status', 'open')
            ->orderBy('year')
            ->orderBy('month')
            ->get(['year', 'month']);

        foreach ($periods as $period) {
            $start = Carbon::create((int) $period->year, (int) $period->month, 1);

            $windows[] = [
                $start->toDateString(),
                $start->copy()->addMonth()->toDateString(),
                $start->format('Y-m'),
            ];
        }

        return $windows;
    }

    /**
     * Print the blockers and answer the question the command was asked.
     */
    private function verdict(bool $perpetual): int
    {
        if ($this->blockers === []) {
            $this->info('SAFE — nothing recorded would be stranded by changing the inventory accounting method.');
            $this->line('');
            $this->line('Change it in config/erp.php ('.self::KEY.') and deploy. Run this');
            $this->line('command again afterwards to confirm the value that is actually in force.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('UNSAFE — changing the inventory accounting method now would corrupt the ledger.');
        $this->line('');

        foreach ($this->blockers as $index => $blocker) {
            $this->line(sprintf('<options=bold>%d. %s</>', $index + 1, $blocker['title']));

            foreach ($blocker['detail'] as $line) {
                $this->line('   '.$line);
            }

            $this->line('   → '.$blocker['consequence']);
            $this->line('');
        }

        $this->warn('Do not change '.self::KEY.' until the items above are settled.');
        $this->line('Moving between the two methods needs a stock revaluation booked at a fiscal-period');
        $this->line('boundary. That is an accountant\'s journal on this company\'s books; this command');
        $this->line('deliberately does not, and cannot, write it. Current method: '
            .($perpetual ? 'PERPETUAL' : 'PERIODIC').'.');
        $this->line('');

        return self::FAILURE;
    }
}
