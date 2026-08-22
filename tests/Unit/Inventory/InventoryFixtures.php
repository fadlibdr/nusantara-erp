<?php

namespace Tests\Unit\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Enums\DocumentStatus;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Services\StockService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;

/**
 * Hand-built inventory fixtures shared by the valuation (tests/Unit/Inventory)
 * and the GL-posting (tests/Feature/Inventory) suites. Deliberately thin: it
 * only assembles rows, it never computes an expected value — every expectation
 * is spelled out in the test that asserts it.
 */
trait InventoryFixtures
{
    protected function stock(): StockService
    {
        return app(StockService::class);
    }

    protected function category(): ItemCategory
    {
        return ItemCategory::query()->firstOrCreate(
            ['code' => 'CAT-UMUM'],
            ['name' => 'Material Umum'],
        );
    }

    protected function makeWarehouse(string $code, array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'code' => $code,
            'name' => "Gudang {$code}",
            'is_active' => true,
        ], $attributes));
    }

    protected function makeItem(string $name, array $attributes = []): Item
    {
        return Item::create(array_merge([
            'name' => $name,
            'category_id' => $this->category()->id,
            'unit' => 'zak',
            'item_type' => ItemType::Material,
            'min_stock' => 0,
            'avg_cost' => 0,
            'last_price' => 0,
            'is_active' => true,
        ], $attributes));
    }

    protected function vendor(): Vendor
    {
        return Vendor::query()->firstOrCreate(
            ['code' => 'VND-0001'],
            [
                'name' => 'PT Semen Distribusi Utama',
                'is_pkp' => true,
                'is_subcontractor' => false,
                'classification' => 'material',
                'payment_term_days' => 30,
                'status' => 'active',
            ],
        );
    }

    /**
     * An approved GOODS purchase order delivering into the warehouse. Which
     * liability a receipt credits follows which document can clear it again:
     * with a PO it is GR/IR (that PO's bill clears it); with a vendor but no PO
     * it is the penerimaan accrual (a manual bill against the receipt clears
     * it); with neither it is the stock variance account, which needs no
     * clearing document at all.
     */
    protected function makeGoodsPurchaseOrder(Warehouse $warehouse, array $attributes = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_merge([
            'vendor_id' => $this->vendor()->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => 1500000,
            'discount_amount' => 0,
            'dpp' => 1500000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 165000,
            'total' => 1665000,
            'status' => DocumentStatus::Approved,
        ], $attributes));
    }

    protected function warehouseUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'gudang@test.local'],
            ['name' => 'Kepala Gudang', 'password' => 'password', 'is_active' => true],
        );
    }

    /**
     * The second pair of eyes on an opname. Approving a stock adjustment writes
     * off inventory value, so the storeman who counted it may not be the one
     * who accepts the difference.
     */
    protected function inventoryApprover(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'manajer-logistik@test.local'],
            ['name' => 'Manajer Logistik', 'password' => 'password', 'is_active' => true],
        );
    }

    /**
     * @param  array<int, array{0: Item, 1: float, 2: float}>  $lines  [item, qty, unit_cost]
     */
    protected function makeGrn(Warehouse $warehouse, array $lines, string $date = '2026-03-10', array $attributes = []): GoodsReceipt
    {
        $grn = GoodsReceipt::create(array_merge([
            'warehouse_id' => $warehouse->id,
            'receipt_date' => $date,
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ], $attributes));

        foreach ($lines as [$item, $qty, $unitCost]) {
            $grn->items()->create([
                'item_id' => $item->id,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'amount' => round($qty * $unitCost, 2),
            ]);
        }

        return $grn->refresh();
    }

    /**
     * Create AND post a receipt — the usual way of giving a warehouse an
     * opening balance at a known cost. With no vendor and no PO this is opening
     * stock, so the GL credit goes to the stock variance account; pass
     * ['vendor_id' => …] to receive it as a delivery that raises an accrual.
     */
    protected function receiveStock(
        Warehouse $warehouse,
        Item $item,
        float $qty,
        float $unitCost,
        string $date = '2026-03-10',
        array $attributes = [],
    ): GoodsReceipt {
        return $this->stock()->postReceipt(
            $this->makeGrn($warehouse, [[$item, $qty, $unitCost]], $date, $attributes)
        );
    }

    /**
     * A third element on a line names the WBS task that consumed it. Left out
     * — as every existing caller leaves it out — the line carries no work
     * package at all, which is deliberately NOT the same thing as inheriting
     * the header: the inheritance is IssueService's rule, and a fixture that
     * quietly reproduced it could not be used to test it.
     *
     * @param  array<int, array{0: Item, 1: float, 2?: int|null}>  $lines  [item, qty, wbs_task_id]
     */
    protected function makeIssue(Warehouse $warehouse, array $lines, ?int $projectId = null, string $date = '2026-03-15', array $attributes = []): Issue
    {
        $issue = Issue::create(array_merge([
            'warehouse_id' => $warehouse->id,
            'project_id' => $projectId,
            'issue_date' => $date,
            'issued_by' => $this->warehouseUser()->id,
            'purpose' => 'Pekerjaan struktur lantai 2',
            'status' => StockDocumentStatus::Draft,
        ], $attributes));

        foreach ($lines as $line) {
            [$item, $qty] = $line;

            $issue->items()->create([
                'item_id' => $item->id,
                'wbs_task_id' => $line[2] ?? null,
                'qty' => $qty,
                'unit_cost' => 0, // filled from the warehouse average at posting
                'amount' => 0,
            ]);
        }

        return $issue->refresh();
    }

    /**
     * @param  array<int, array{0: Item, 1: float}>  $lines  [item, qty]
     */
    protected function makeTransfer(Warehouse $from, Warehouse $to, array $lines, string $date = '2026-03-20'): Transfer
    {
        $transfer = Transfer::create([
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'transfer_date' => $date,
            'status' => TransferStatus::Draft,
        ]);

        foreach ($lines as [$item, $qty]) {
            $transfer->items()->create([
                'item_id' => $item->id,
                'qty' => $qty,
                'unit_cost' => 0, // frozen at the source average on send
            ]);
        }

        return $transfer->refresh();
    }

    /**
     * Opname sheet built through StockAdjustmentService so system_qty / diff_qty
     * are snapshotted exactly as production does it.
     *
     * @param  array<int, array{0: Item, 1: float}>  $lines  [item, counted_qty]
     */
    protected function makeAdjustment(Warehouse $warehouse, array $lines, string $date = '2026-03-25', bool $approve = true): StockAdjustment
    {
        $adjustment = app(StockAdjustmentService::class)->create([
            'warehouse_id' => $warehouse->id,
            'adjustment_date' => $date,
            'reason' => 'opname',
            'items' => array_map(
                fn (array $line): array => ['item_id' => $line[0]->id, 'counted_qty' => $line[1]],
                $lines,
            ),
        ]);

        if ($approve) {
            // Two people: an opname the storeman both counts and signs off is
            // the shape maker-checker refuses, and it is also not how a real
            // stock count is authorised.
            $adjustment->submit($this->warehouseUser())->approve($this->inventoryApprover());
        }

        return $adjustment->refresh();
    }

    protected function balanceOf(Warehouse $warehouse, Item $item): ?StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('item_id', $item->id)
            ->first();
    }

    protected function balanceQty(Warehouse $warehouse, Item $item): float
    {
        return (float) ($this->balanceOf($warehouse, $item)?->qty ?? 0);
    }

    protected function balanceAvg(Warehouse $warehouse, Item $item): float
    {
        return (float) ($this->balanceOf($warehouse, $item)?->avg_cost ?? 0);
    }

    /**
     * qty * avg_cost held in one warehouse for one item.
     */
    protected function balanceValue(Warehouse $warehouse, Item $item): float
    {
        return round($this->balanceQty($warehouse, $item) * $this->balanceAvg($warehouse, $item), 2);
    }

    /**
     * @return Collection<int, StockLedgerEntry>
     */
    protected function ledgerFor(Warehouse $warehouse, Item $item): Collection
    {
        return StockLedgerEntry::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('item_id', $item->id)
            ->orderBy('id')
            ->get();
    }
}
