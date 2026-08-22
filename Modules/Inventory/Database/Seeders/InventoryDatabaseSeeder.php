<?php

namespace Modules\Inventory\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\NumberSequence;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FiscalPeriodSeeder;
use Modules\Finance\Models\Account;
use Modules\Finance\Services\JournalService;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Projects\Database\Seeders\ProjectsDatabaseSeeder;

/**
 * Demo dataset for Inventory, and the one place the demo's stock sub-ledger is
 * reconciled with the general ledger.
 *
 * WHY THIS SEEDER TOUCHES FINANCE. DatabaseSeeder runs Inventory fifth and
 * Finance eleventh, so on a fresh install the chart of accounts is still empty
 * when the stock documents below post. StockService::ledgerPostingEnabled()
 * correctly refuses to journal against an empty chart, and the demo shipped for
 * exactly that reason with Rp 332.510.000 of stock on hand and GL 1-1400 at
 * 0,00 — a trial balance that balances while disagreeing with its own
 * sub-ledger, which is worse than an obviously broken one. bootstrapLedger()
 * seeds Finance's two master-data seeders (both idempotent and production-safe;
 * FinanceDatabaseSeeder calls them again later) so everything after it journals
 * for real. Inventory -> Finance is the existing dependency direction, and the
 * call is guarded so Inventory still seeds standalone.
 *
 * HOW OPENING STOCK IS BOOKED. Opening stock is not a receipt event: there is
 * no purchase order, no vendor, no liability, and no profit-and-loss event. It
 * is the company's starting position, and its counter-entry is equity:
 *
 *   Dr 1-1400 Persediaan Material   nilai stok awal
 *   Cr 3-3100 Saldo Awal            idem
 *
 * None of the receipt engine's three credit paths says that. 2-1150 and 2-1600
 * are liabilities a vendor bill has to clear and there is no vendor here; the
 * third path credits 6-4400 Selisih Persediaan, which is right for found stock
 * and returns from site but would report the whole opening inventory as
 * operating income in the go-live year. So the opening receipt is posted for
 * the sub-ledger only — before bootstrapLedger(), while the GL bridge is
 * deliberately still down — and postOpeningBalanceJournal() writes the GL side
 * itself. That method posts nothing when the receipt already carries a journal,
 * so re-running this seeder, or reordering the two calls, degrades to the
 * engine's own treatment instead of double-booking the stock.
 *
 * WHY IT ALSO TOUCHES PROJECTS. Same ordering problem, different victim:
 * Projects seeds seventh, so PRJ-2026-001 did not exist when seedIssue() posted
 * the site material issue. The issue stored project_id = null and StockService —
 * correctly, for an issue that names no project — debited 6-4100 Beban Umum &
 * Administrasi instead of 5-1100 Beban Material and wrote no realisasi row, so
 * the demo shipped Rp 18.740.000 of Graha Sentosa column-casting material as
 * office overhead and a project-profitability report short by exactly that.
 * bootstrapProjects() runs ProjectsDatabaseSeeder first (idempotent throughout;
 * DatabaseSeeder calls it again at its own turn, which is when boq_id picks up
 * the Estimation rows that still do not exist here), so the issue is posted
 * against a real project by the engine's own rules.
 */
class InventoryDatabaseSeeder extends Seeder
{
    /**
     * Fallbacks matching config/erp.php, used when the config is unreadable.
     */
    private const DEFAULT_INVENTORY_ACCOUNT = '1-1400';

    private const DEFAULT_OPENING_BALANCE_ACCOUNT = '3-3100';

    /** Canon code (CONVENTIONS.md §8) of the opening-stock receipt. */
    private const OPENING_STOCK_CODE = 'GRN/2026/VII/0001';

    public function run(): void
    {
        // Categories live in a dedicated seeder so ProductionSeeder can run
        // them without the demo items/warehouses/stock below.
        $this->call(ItemCategorySeeder::class);

        $this->seedItems();

        // Before the warehouses, so the two site warehouses resolve their
        // project on the way in instead of waiting for Projects to back-fill
        // them, and before every stock document that names a project.
        $this->bootstrapProjects();
        $this->seedWarehouses();

        // Stock documents are posted through StockService so balances and the
        // ledger stay consistent. Each block is skipped when its canonical
        // document already exists (posting twice would double the stock).
        //
        // Order is load-bearing, see the class docblock: opening stock goes in
        // while the GL bridge is down, the ledger is bootstrapped, the opening
        // balance is journaled to equity, and only then do the movements that
        // DO belong in the ledger by the engine's own rules run.
        $this->seedOpeningStock();
        $this->bootstrapLedger();
        $this->postOpeningBalanceJournal();

        $this->seedTransfer();
        $this->seedIssue();

        $this->syncNumberSequences();
    }

    /**
     * The canonical opening-stock receipt, or null when it has not been seeded.
     */
    private function openingStockReceipt(): ?GoodsReceipt
    {
        return GoodsReceipt::withTrashed()->where('code', self::OPENING_STOCK_CODE)->first();
    }

    /**
     * Seed Finance's master data early so the stock movements after this call
     * reach the general ledger.
     *
     * Both seeders are idempotent (updateOrCreate) and are documented as safe to
     * run on their own — FinanceDatabaseSeeder calls exactly these two before
     * its own demo documents, so running them here only moves them earlier. The
     * guards keep Inventory seedable without Finance on disk.
     */
    private function bootstrapLedger(): void
    {
        if (! class_exists(ChartOfAccountsSeeder::class)
            || ! Schema::hasTable('fin_accounts')
            || ! Schema::hasTable('fin_fiscal_periods')) {
            return;
        }

        if (Account::query()->exists()) {
            return; // already seeded (re-run, or Finance seeded first)
        }

        $this->call([
            ChartOfAccountsSeeder::class,
            FiscalPeriodSeeder::class,
        ]);
    }

    /**
     * Seed the projects early so the stock documents below can name one.
     *
     * seedIssue() and seedWarehouses() both look up `prj_projects` by canon
     * code, and on a single-pass db:seed Projects has not run yet — see the
     * class docblock for what that cost the demo. ProjectsDatabaseSeeder is
     * idempotent (updateOrCreate everywhere, WBS rebuilt from scratch) and
     * DatabaseSeeder calls it again in its own slot, so running it here only
     * moves it earlier. The guards keep Inventory seedable without Projects on
     * disk, and the emptiness check keeps a re-run or a Projects-first order
     * from seeding it twice in one pass.
     */
    private function bootstrapProjects(): void
    {
        if (! class_exists(ProjectsDatabaseSeeder::class) || ! Schema::hasTable('prj_projects')) {
            return;
        }

        if (DB::table('prj_projects')->exists()) {
            return; // already seeded (re-run, or Projects seeded first)
        }

        $this->call(ProjectsDatabaseSeeder::class);
    }

    /**
     * GL side of the opening stock: Dr persediaan / Cr saldo awal (ekuitas).
     *
     * Posted here rather than by StockService because an opening balance has no
     * counterparty and no P&L effect — see the class docblock. Idempotent: a
     * receipt that already carries a journal is left alone, whoever posted it.
     */
    private function postOpeningBalanceJournal(): void
    {
        $grn = $this->openingStockReceipt();

        if ($grn === null
            || $grn->status !== StockDocumentStatus::Posted
            || ! class_exists(JournalService::class)
            || ! Schema::hasTable('fin_journals')) {
            return;
        }

        if ($this->hasReceiptJournal((int) $grn->id)) {
            return;
        }

        $value = round((float) $grn->items()->sum('amount'), 2);

        if ($value <= 0.0) {
            return;
        }

        $inventoryCode = (string) config('erp.accounting.inventory_account', self::DEFAULT_INVENTORY_ACCOUNT);
        $openingCode = (string) config('erp.accounting.opening_balance_account', self::DEFAULT_OPENING_BALANCE_ACCOUNT);

        if (! $this->isPostable($inventoryCode) || ! $this->isPostable($openingCode)) {
            return; // chart not (fully) seeded: leave the demo obviously unbooked
        }

        app(JournalService::class)->autoPost(
            'goods_receipt',
            (int) $grn->id,
            [
                [
                    'account_code' => $inventoryCode,
                    'debit' => $value,
                    'description' => "Stok awal {$grn->code}",
                ],
                [
                    'account_code' => $openingCode,
                    'credit' => $value,
                    'description' => "Saldo awal persediaan {$grn->code}",
                ],
            ],
            $grn->receipt_date->toDateString(),
            "GRN {$grn->code} — saldo awal persediaan",
            $this->firstUserId(),
        );
    }

    private function isPostable(string $code): bool
    {
        return Account::query()->where('code', $code)->where('is_postable', true)->exists();
    }

    /**
     * Whether the general ledger already carries an entry for this receipt,
     * whoever wrote it — StockService, this seeder, or a data migration.
     */
    private function hasReceiptJournal(int $goodsReceiptId): bool
    {
        if (! Schema::hasTable('fin_journals')) {
            return false;
        }

        return DB::table('fin_journals')
            ->whereNull('deleted_at')
            ->where('reference_type', 'goods_receipt')
            ->where('reference_id', $goodsReceiptId)
            ->exists();
    }

    private function seedItems(): void
    {
        $items = [
            ['code' => 'ITM-0001', 'name' => 'Semen Portland 50kg', 'category' => 'SIPIL', 'unit' => 'zak', 'item_type' => 'material', 'min_stock' => 200, 'last_price' => 62000],
            ['code' => 'ITM-0002', 'name' => 'Besi Beton D16', 'category' => 'SIPIL', 'unit' => 'btg', 'item_type' => 'material', 'min_stock' => 100, 'last_price' => 118000],
            ['code' => 'ITM-0003', 'name' => 'Kabel UTP Cat6', 'category' => 'ICT', 'unit' => 'roll', 'item_type' => 'material', 'min_stock' => 5, 'last_price' => 1150000],
            ['code' => 'ITM-0004', 'name' => 'CCTV Dome 4MP', 'category' => 'ICT', 'unit' => 'unit', 'item_type' => 'material', 'min_stock' => 4, 'last_price' => 1850000],
            ['code' => 'ITM-0005', 'name' => 'Pasir Beton', 'category' => 'SIPIL', 'unit' => 'm3', 'item_type' => 'material', 'min_stock' => 20, 'last_price' => 285000],
            ['code' => 'ITM-0006', 'name' => 'Switch Managed 24 Port', 'category' => 'ICT', 'unit' => 'unit', 'item_type' => 'material', 'min_stock' => 2, 'last_price' => 4200000],
            ['code' => 'ITM-0007', 'name' => 'Ready Mix K-300', 'category' => 'SIPIL', 'unit' => 'm3', 'item_type' => 'material', 'min_stock' => 0, 'last_price' => 950000],
            ['code' => 'ITM-0008', 'name' => 'Access Point WiFi 6', 'category' => 'ICT', 'unit' => 'unit', 'item_type' => 'material', 'min_stock' => 4, 'last_price' => 2300000],
        ];

        foreach ($items as $item) {
            $categoryId = ItemCategory::query()->where('code', $item['category'])->value('id');

            if ($categoryId === null) {
                continue;
            }

            Item::withTrashed()->updateOrCreate(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'category_id' => $categoryId,
                    'unit' => $item['unit'],
                    'barcode' => null,
                    'item_type' => $item['item_type'],
                    'min_stock' => $item['min_stock'],
                    'last_price' => $item['last_price'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedWarehouses(): void
    {
        $warehouses = [
            [
                'code' => 'WH-PUSAT',
                'name' => 'Gudang Pusat (Cakung)',
                'project_code' => null,
                'address' => 'Jl. Raya Cakung Cilincing Km 2, Jakarta Timur',
                'keeper_code' => 'EMP-0005',
            ],
            [
                'code' => 'WH-PRJ-2026-001',
                'name' => 'Gudang Site Proyek Graha Sentosa',
                'project_code' => 'PRJ-2026-001',
                'address' => 'Site Proyek Gedung Graha Sentosa, Jl. TB Simatupang Kav. 18, Jakarta Selatan',
                'keeper_code' => 'EMP-0003',
            ],
            [
                'code' => 'WH-PRJ-2026-002',
                'name' => 'Gudang Site Bank Artha Nusantara',
                'project_code' => 'PRJ-2026-002',
                'address' => 'Menara Artha Basement 2, Jl. Jend. Sudirman Kav. 34, Jakarta Pusat',
                'keeper_code' => 'EMP-0007',
            ],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::withTrashed()->updateOrCreate(
                ['code' => $warehouse['code']],
                [
                    'name' => $warehouse['name'],
                    'project_id' => $this->lookupId('prj_projects', $warehouse['project_code']),
                    'address' => $warehouse['address'],
                    'keeper_employee_id' => $this->lookupId('hr_employees', $warehouse['keeper_code']),
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Opening stock: one GRN into the central warehouse, posted through
     * StockService so the moving average, balances and ledger are real.
     *
     * NO VENDOR AND NO PO, and both are the point. This is the stock the company
     * already owned when the system went live, not a delivery someone will
     * invoice. Naming a vendor would send the credit to 2-1600 Beban Yang Masih
     * Harus Dibayar and leave the demo carrying a Rp 351 juta payable that no
     * bill exists to clear; naming a PO would do the same to 2-1150. The GL side
     * is postOpeningBalanceJournal()'s job.
     */
    private function seedOpeningStock(): void
    {
        $code = self::OPENING_STOCK_CODE;

        $existing = $this->openingStockReceipt();

        if ($existing) {
            // Already seeded and posted — never post twice. Earlier builds of
            // this seeder back-filled a vendor here, which reclassified an
            // opening balance into an unbillable accrual. Undo it, but only
            // while nothing has been booked against that vendor yet: once a
            // journal exists, correcting it is an accountant's decision and the
            // opening balance data migration handles it.
            if ($existing->vendor_id !== null && ! $this->hasReceiptJournal((int) $existing->id)) {
                $existing->forceFill(['vendor_id' => null])->save();
            }

            return;
        }

        $warehouse = Warehouse::query()->where('code', 'WH-PUSAT')->first();

        if (! $warehouse) {
            return;
        }

        $grn = new GoodsReceipt([
            'warehouse_id' => $warehouse->id,
            'purchase_order_id' => null, // opening balance, not a purchase
            'vendor_id' => null,         // opening balance, no counterparty
            'receipt_date' => '2026-07-01',
            'delivery_note_no' => '',    // no delivery: nothing was delivered
            'received_by' => $this->firstUserId(),
            'status' => StockDocumentStatus::Draft,
            'notes' => 'Saldo awal persediaan gudang pusat per 1 Juli 2026 — hasil migrasi data, '
                .'bukan penerimaan dari vendor.',
        ]);
        $grn->code = $code;
        $grn->save();

        $lines = [
            ['item_code' => 'ITM-0001', 'qty' => 1200, 'unit_cost' => 62000],
            ['item_code' => 'ITM-0002', 'qty' => 800, 'unit_cost' => 118000],
            ['item_code' => 'ITM-0005', 'qty' => 150, 'unit_cost' => 285000],
            ['item_code' => 'ITM-0003', 'qty' => 20, 'unit_cost' => 1150000],
            ['item_code' => 'ITM-0004', 'qty' => 30, 'unit_cost' => 1850000],
            ['item_code' => 'ITM-0006', 'qty' => 8, 'unit_cost' => 4200000],
            ['item_code' => 'ITM-0008', 'qty' => 12, 'unit_cost' => 2300000],
        ];

        foreach ($lines as $line) {
            $itemId = Item::query()->where('code', $line['item_code'])->value('id');

            if ($itemId === null) {
                continue;
            }

            $grn->items()->create([
                'item_id' => $itemId,
                'po_item_id' => null,
                'qty' => $line['qty'],
                'unit_cost' => $line['unit_cost'],
                'amount' => round($line['qty'] * $line['unit_cost'], 2),
            ]);
        }

        app(StockService::class)->postReceipt($grn);
    }

    /**
     * Transfer of structural material from the central warehouse to the Graha
     * Sentosa site, sent and received so it lands in the site balance.
     */
    private function seedTransfer(): void
    {
        $code = 'TRF/2026/VII/0001';

        if (Transfer::withTrashed()->where('code', $code)->exists()) {
            return;
        }

        $from = Warehouse::query()->where('code', 'WH-PUSAT')->first();
        $to = Warehouse::query()->where('code', 'WH-PRJ-2026-001')->first();

        if (! $from || ! $to) {
            return;
        }

        $transfer = new Transfer([
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'transfer_date' => '2026-07-03',
            'status' => TransferStatus::Draft,
            'notes' => 'Mobilisasi material struktur ke site Graha Sentosa untuk pekerjaan kolom lantai 1.',
        ]);
        $transfer->code = $code;
        $transfer->save();

        $lines = [
            ['item_code' => 'ITM-0001', 'qty' => 500],
            ['item_code' => 'ITM-0002', 'qty' => 300],
        ];

        foreach ($lines as $line) {
            $itemId = Item::query()->where('code', $line['item_code'])->value('id');

            if ($itemId === null) {
                continue;
            }

            $transfer->items()->create([
                'item_id' => $itemId,
                'qty' => $line['qty'],
                'unit_cost' => 0, // frozen at send below
            ]);
        }

        $stock = app(StockService::class);
        $stock->sendTransfer($transfer);
        $stock->receiveTransfer($transfer);
    }

    /**
     * Material issue at the site warehouse, costed at the moving average.
     */
    private function seedIssue(): void
    {
        $code = 'ISS/2026/VII/0001';

        $existing = Issue::withTrashed()->where('code', $code)->first();

        if ($existing) {
            // Already seeded and posted — never post twice. Only repair the
            // cross-module link that was null when Projects seeded later.
            if ($existing->project_id === null) {
                $existing->forceFill(['project_id' => $this->lookupId('prj_projects', 'PRJ-2026-001')])->save();
            }

            return;
        }

        $warehouse = Warehouse::query()->where('code', 'WH-PRJ-2026-001')->first();

        if (! $warehouse) {
            return;
        }

        $issue = new Issue([
            'warehouse_id' => $warehouse->id,
            'project_id' => $this->lookupId('prj_projects', 'PRJ-2026-001'),
            'wbs_task_id' => null,
            'issue_date' => '2026-07-05',
            'issued_by' => $this->firstUserId(),
            'purpose' => 'Pengecoran kolom lantai 1 zona A — Gedung Graha Sentosa.',
            'status' => StockDocumentStatus::Draft,
        ]);
        $issue->code = $code;
        $issue->save();

        $lines = [
            ['item_code' => 'ITM-0001', 'qty' => 150],
            ['item_code' => 'ITM-0002', 'qty' => 80],
        ];

        foreach ($lines as $line) {
            $itemId = Item::query()->where('code', $line['item_code'])->value('id');

            if ($itemId === null) {
                continue;
            }

            $issue->items()->create([
                'item_id' => $itemId,
                'qty' => $line['qty'],
                'unit_cost' => 0, // valued at warehouse avg cost at posting
                'amount' => 0,
            ]);
        }

        app(StockService::class)->postIssue($issue);
    }

    /**
     * Seeded codes use explicit sequence number 1; move the 2026 counters past
     * it so runtime-generated GRN/ISS/TRF numbers never collide with the canon.
     */
    private function syncNumberSequences(): void
    {
        foreach (['GRN', 'ISS', 'TRF'] as $type) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => 2026],
                ['last_number' => 0],
            );

            if ((int) $sequence->last_number < 1) {
                $sequence->update(['last_number' => 1]);
            }
        }
    }

    private function firstUserId(): ?int
    {
        return User::query()->orderBy('id')->value('id');
    }

    /**
     * Cross-module lookup by canonical seed code; null when the owning module
     * has not been migrated/seeded yet (columns are nullable by design).
     */
    private function lookupId(string $table, ?string $code): ?int
    {
        if ($code === null || ! Schema::hasTable($table)) {
            return null;
        }

        $id = DB::table($table)->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
