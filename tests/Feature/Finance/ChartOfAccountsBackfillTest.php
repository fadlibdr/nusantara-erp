<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * H3 — the defect the whole 688-test suite missed, because every test seeds a
 * complete, fresh chart of accounts and no installation in the field does.
 *
 * ChartOfAccountsSeeder gained 2-1150 (GR/IR), 6-4400 (selisih persediaan) and
 * 6-4500 (selisih harga pembelian), but a seeder is not re-run on an existing
 * database. On the shipped demo database, posting ANY goods receipt threw
 *
 *   LogicException: COA account 2-1150 does not exist; seed the chart of
 *   accounts first.
 *
 * — the inventory module was simply bricked. Two things are asserted here:
 * that the failure is an actionable 422 naming the missing account with the
 * stock movement rolled back (never a 500, never a half-posted movement), and
 * that the data migration which back-fills the accounts is safe to run against
 * a chart that already has them.
 */
class ChartOfAccountsBackfillTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    /** The three accounts the perpetual inventory engine posts to. */
    private const BACKFILLED = ['2-1150', '6-4400', '6-4500'];

    private Warehouse $pusat;

    private Item $semen;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->project = $this->makeProject();
    }

    // ---------------------------------------------------------------- the failure mode

    public function test_posting_a_receipt_without_the_clearing_account_is_a_422_naming_it_and_moves_no_stock(): void
    {
        // Exactly the state of the shipped database: the schema is complete,
        // the chart of accounts is seeded, but the three newer accounts the
        // inventory engine posts to were never added to it.
        $this->dropAccounts(...self::BACKFILLED);

        $po = $this->makeGoodsPo();
        $grn = $this->makeGrnFor($po, 100, 62000); // 100 * 62.000 = 6.200.000

        $this->actingAs($this->adminUser(), 'sanctum');

        $response = $this->postJson("/api/inventory/goods-receipts/{$grn->id}/post");

        // Actionable for the operator: a 422 that names the account to create,
        // not a 500 and not a silent success.
        $response->assertStatus(422);
        $this->assertStringContainsString('2-1150', (string) $response->json('message'));
        $this->assertStringContainsString('does not exist', (string) $response->json('message'));

        // And the stock movement rolled back with the journal: no balance, no
        // ledger row, no received quantity on the PO, document still a draft.
        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
        $this->assertSame(0, StockBalance::query()->where('qty', '>', 0)->count());
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0.0, (float) $po->items()->value('qty_received'));
        $this->assertSame(0.0, (float) $this->semen->fresh()->last_price);
    }

    public function test_posting_an_opname_without_the_stock_variance_account_is_refused_and_rolls_back(): void
    {
        $this->dropAccounts('6-4400');

        // Stock in from a vendor without a PO: 100 zak @ 15.000 = 1.500.000,
        // accrued in 2-1600. The vendor matters here — a receipt with neither PO
        // nor vendor credits 6-4400, the very account this test has dropped.
        $this->receiveStock($this->pusat, $this->semen, 100, 15000, '2026-03-01', [
            'vendor_id' => $this->vendor()->id,
        ]);

        // Counted 95: a shortage of 5 * 15.000 = 75.000 to book against 6-4400.
        $adjustment = $this->makeAdjustment($this->pusat, [[$this->semen, 95]], '2026-03-25');

        try {
            $this->stock()->postAdjustment($adjustment);
            $this->fail('Expected a LogicException naming the missing variance account.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('6-4400', $e->getMessage());
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        // The count was refused whole: quantity, value and posting stamp intact.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(1500000.0, $this->balanceValue($this->pusat, $this->semen));
        $this->assertNull($adjustment->fresh()->posted_at);
        $this->assertSame(1, Journal::query()->count()); // only the opening receipt
    }

    public function test_a_matched_bill_without_the_purchase_variance_account_is_refused_and_rolls_back(): void
    {
        $this->dropAccounts('6-4500');

        // PO at 62.000, received at 65.000 => a 300.000 difference that has
        // nowhere to go while 6-4500 is missing.
        $po = $this->makeGoodsPo();
        $this->stock()->postReceipt($this->makeGrnFor($po, 100, 65000));

        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $bill->submit($this->financeUser());

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
            $this->fail('Expected a LogicException naming the missing variance account.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('6-4500', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $bill->fresh()->status);
        $this->assertDatabaseMissing('fin_journals', ['reference_type' => 'ap_bill']);
        $this->assertSame(0, ProjectCost::query()->count());

        // The receipt journal predates the bill and stands: 100 * 65.000 =
        // 6.500.000 still credited to GR/IR, waiting for a repaired chart.
        $this->assertSame(1, Journal::query()->count());
    }

    // ---------------------------------------------------------------- the back-fill

    public function test_the_back_fill_migration_repairs_an_installation_that_lacks_the_accounts(): void
    {
        $this->dropAccounts(...self::BACKFILLED);

        $this->backfillMigration()->up();

        $expected = [
            '2-1150' => ['Penerimaan Barang Belum Ditagih', 'liability', 'credit', '2-1000'],
            '6-4400' => ['Selisih Persediaan', 'expense', 'debit', '6-0000'],
            '6-4500' => ['Selisih Harga Pembelian', 'expense', 'debit', '6-0000'],
        ];

        foreach ($expected as $code => [$name, $type, $normal, $parentCode]) {
            $account = Account::query()->where('code', $code)->sole();

            $this->assertSame($name, $account->name);
            $this->assertSame($type, $account->account_type->value);
            $this->assertSame($normal, $account->normal_balance->value);
            $this->assertTrue($account->is_postable, "{$code} must be postable.");
            $this->assertTrue($account->is_active);
            $this->assertSame(
                (int) Account::query()->where('code', $parentCode)->value('id'),
                (int) $account->parent_id,
                "{$code} must hang under {$parentCode}.",
            );
        }

        // The module is usable again: the receipt that threw now posts.
        $po = $this->makeGoodsPo();
        $grn = $this->stock()->postReceipt($this->makeGrnFor($po, 100, 62000));

        // 100 * 62.000 = 6.200.000 Dr 1-1400 / Cr 2-1150.
        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));
        $this->assertSame(6200000.0, $lines['1-1400']['debit']);
        $this->assertSame(6200000.0, $lines['2-1150']['credit']);
    }

    public function test_the_back_fill_migration_is_idempotent_and_keeps_a_customised_name(): void
    {
        // An operator renamed the account on their own chart.
        Account::query()->where('code', '2-1150')->update(['name' => 'Kliring Penerimaan Barang (nama kustom)']);

        $accountsBefore = Account::withTrashed()->count();
        $migration = $this->backfillMigration();

        $migration->up();
        $migration->up(); // deliberately twice: migrations get re-run by hand

        foreach (self::BACKFILLED as $code) {
            $this->assertSame(
                1,
                Account::withTrashed()->where('code', $code)->count(),
                "Exactly one {$code} row must exist after the back-fill.",
            );
        }

        // A customised name survives; the untouched ones keep the shipped name.
        $this->assertSame(
            'Kliring Penerimaan Barang (nama kustom)',
            Account::query()->where('code', '2-1150')->value('name'),
        );
        $this->assertSame('Selisih Persediaan', Account::query()->where('code', '6-4400')->value('name'));
        $this->assertSame('Selisih Harga Pembelian', Account::query()->where('code', '6-4500')->value('name'));

        // No row was added and none was removed.
        $this->assertSame($accountsBefore, Account::withTrashed()->count());
    }

    public function test_the_back_fill_migration_restores_a_soft_deleted_clearing_account(): void
    {
        // The unique index still holds the code, so a replacement cannot be
        // inserted, and JournalService resolves codes through the non-trashed
        // scope: a trashed 2-1150 breaks the engine exactly like a missing one.
        Account::query()->where('code', '2-1150')->delete();

        $this->assertNull(Account::query()->where('code', '2-1150')->first());

        $this->backfillMigration()->up();

        $account = Account::query()->where('code', '2-1150')->sole();
        $this->assertFalse($account->trashed());
        $this->assertSame(1, Account::withTrashed()->where('code', '2-1150')->count());

        $po = $this->makeGoodsPo();
        $grn = $this->stock()->postReceipt($this->makeGrnFor($po, 100, 62000));

        // 100 * 62.000 = 6.200.000 credited to the restored account.
        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));
        $this->assertSame(6200000.0, $lines['2-1150']['credit']);
    }

    public function test_the_back_fill_migration_leaves_an_unseeded_chart_of_accounts_alone(): void
    {
        // A fresh install runs the seeder, which ships all three accounts.
        // Dropping three orphan rows into an empty chart would fake a seeded
        // one — including for the "is the COA seeded yet?" probe in
        // StockService::ledgerPostingEnabled().
        // Children first: fin_accounts.parent_id points back at the same table.
        Account::withTrashed()
            ->orderByDesc('id')
            ->get()
            ->each(fn (Account $account) => $account->forceDelete());

        $this->assertSame(0, Account::withTrashed()->count());

        $this->backfillMigration()->up();

        $this->assertSame(0, Account::withTrashed()->count());
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * The data migration, loaded straight from the Finance module. Located by
     * glob so renumbering the migration does not silently skip this test.
     */
    private function backfillMigration(): object
    {
        $files = glob(base_path('Modules/Finance/Database/Migrations/*_backfill_inventory_gl_accounts.php'));

        $this->assertIsArray($files);
        $this->assertCount(1, $files, 'The inventory GL account back-fill migration is missing.');

        return require $files[0];
    }

    /**
     * Remove accounts as if they had never been seeded (force, not soft: an
     * installation created before the seeder grew has no row at all).
     */
    private function dropAccounts(string ...$codes): void
    {
        Account::withTrashed()
            ->whereIn('code', $codes)
            ->get()
            ->each(fn (Account $account) => $account->forceDelete());

        foreach ($codes as $code) {
            $this->assertSame(0, Account::withTrashed()->where('code', $code)->count());
        }
    }

    /**
     * An approved goods PO: 100 zak @ 62.000 = 6.200.000 dpp, PPN 11% =
     * 682.000, total 6.882.000.
     */
    private function makeGoodsPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->makeVendor()->id,
            'project_id' => $this->project->id,
            'warehouse_id' => $this->pusat->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => 6200000,
            'discount_amount' => 0,
            'dpp' => 6200000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 682000,
            'total' => 6882000,
            'status' => DocumentStatus::Approved,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->semen->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 100,
            'unit' => 'zak',
            'unit_price' => 62000,
            'amount' => 6200000,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    private function makeGrnFor(PurchaseOrder $po, float $qty, float $unitCost): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => '2026-03-05',
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => $this->semen->id,
            'po_item_id' => $po->items()->value('id'),
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => round($qty * $unitCost, 2),
        ]);

        return $grn->refresh();
    }
}
