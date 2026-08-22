<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * DELETING AN ITEM MUST NOT STRAND STOCK, AND A DELETED ONE MUST NOT 500.
 *
 * postReceipt() has always refused a soft-deleted item with a business-rule
 * message the controller turns into a 422. receiveTransfer() and
 * postAdjustment() dereferenced the same null relation instead:
 *
 *   receiveTransfer  TypeError: StockService::refreshGlobalAvgCost(): Argument
 *       #1 ($item) must be of type Item, null given
 *   postAdjustment   ErrorException: Attempt to read property "avg_cost" on null
 *
 * TransferController and StockAdjustmentController catch DomainException and
 * LogicException; neither of those is either, so both reached the user as a bare
 * 500. The transfer case is the expensive one, and ItemController::destroy is
 * why it was reachable at all: TransferStatus's own docblock says goods on the
 * road are "visible in neither balance", so a fully-transferred item reads qty 0
 * everywhere and passed the "still has stock" guard. The audit measured Rp
 * 11.500.000 of Kabel UTP Cat6 that had left WH-A, could never arrive at WH-B,
 * sat in 1-1400 with a sub-ledger of Rp 0 behind it, and had no restore endpoint
 * and no cancellation to repair it with.
 */
class DeletedItemGuardsTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $a;

    private Warehouse $b;

    private Item $kabel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->a = $this->makeWarehouse('WH-A');
        $this->b = $this->makeWarehouse('WH-B');
        $this->kabel = $this->makeItem('Kabel UTP Cat6', ['unit' => 'roll']);
    }

    // ------------------------------------------------ the item cannot be deleted

    public function test_an_item_whose_stock_is_on_the_road_cannot_be_deleted(): void
    {
        // 10 roll @ 1.150.000 = Rp 11.500.000, all of it in transit.
        $this->receiveStock($this->a, $this->kabel, 10, 1150000, '2026-03-01');
        $this->stock()->sendTransfer($this->makeTransfer($this->a, $this->b, [[$this->kabel, 10]], '2026-03-05'));

        // The state that fooled the old guard: not one balance row holds it.
        $this->assertFalse(
            StockBalance::query()->where('item_id', $this->kabel->id)->where('qty', '>', 0)->exists()
        );

        $this->actingAs($this->adminUser(), 'sanctum')
            ->deleteJson("/api/inventory/items/{$this->kabel->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Item ini sedang dalam perjalanan antar gudang dan tidak dapat dihapus. '
                .'Terima dulu transfernya di gudang tujuan, baru hapus itemnya.');

        $this->assertNull($this->kabel->fresh()->deleted_at);
    }

    public function test_the_same_item_deletes_once_the_transfer_has_landed(): void
    {
        $this->receiveStock($this->a, $this->kabel, 10, 1150000, '2026-03-01');
        $transfer = $this->makeTransfer($this->a, $this->b, [[$this->kabel, 10]], '2026-03-05');
        $this->stock()->sendTransfer($transfer);
        $this->stock()->receiveTransfer($transfer->fresh());

        // Consume it so the balance guard is satisfied too, then the item really
        // is finished with and the delete goes through as it always did.
        $this->stock()->postIssue($this->makeIssue($this->b, [[$this->kabel, 10]], null, '2026-03-10'));

        $this->actingAs($this->adminUser(), 'sanctum')
            ->deleteJson("/api/inventory/items/{$this->kabel->id}")
            ->assertOk();

        $this->assertNotNull($this->kabel->fresh()->deleted_at);
        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
    }

    public function test_an_item_still_sitting_in_a_warehouse_is_refused_as_before(): void
    {
        $this->receiveStock($this->a, $this->kabel, 10, 1150000, '2026-03-01');

        $this->actingAs($this->adminUser(), 'sanctum')
            ->deleteJson("/api/inventory/items/{$this->kabel->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Item masih memiliki stok dan tidak dapat dihapus.');
    }

    // ------------------------------- and if one slips through, it is a refusal

    public function test_receiving_a_transfer_whose_item_was_deleted_refuses_instead_of_crashing(): void
    {
        $this->receiveStock($this->a, $this->kabel, 10, 1150000, '2026-03-01');
        $transfer = $this->makeTransfer($this->a, $this->b, [[$this->kabel, 10]], '2026-03-05');
        $this->stock()->sendTransfer($transfer);

        // Belt and braces: the controller guard is closed, so force the state it
        // used to allow and prove the service is no longer the thing that breaks.
        $this->kabel->delete();

        try {
            $this->stock()->receiveTransfer($transfer->fresh());
            $this->fail('Expected a deleted item to be refused, not to crash.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('which has been deleted', $e->getMessage());
            $this->assertStringContainsString($transfer->code, $e->getMessage());
        }

        $this->assertSame(TransferStatus::InTransit, $transfer->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->b, $this->kabel));
    }

    public function test_a_transfer_receives_normally_while_its_item_is_alive(): void
    {
        $this->receiveStock($this->a, $this->kabel, 10, 1150000, '2026-03-01');
        $transfer = $this->makeTransfer($this->a, $this->b, [[$this->kabel, 10]], '2026-03-05');
        $this->stock()->sendTransfer($transfer);

        $this->stock()->receiveTransfer($transfer->fresh());

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(10.0, $this->balanceQty($this->b, $this->kabel));
        $this->assertSame(1150000.0, $this->balanceAvg($this->b, $this->kabel));
    }

    public function test_posting_an_opname_whose_item_was_deleted_refuses_instead_of_crashing(): void
    {
        // A found-stock line is precisely a line for an item the warehouse does
        // not hold, so the item can pass the delete guard between the count and
        // the approval — and the sheet is frozen once approved.
        $langka = $this->makeItem('Konektor RJ45 Lawas', ['unit' => 'pcs']);
        $adjustment = $this->makeAdjustment($this->a, [[$langka, 40]], '2026-03-25');

        $langka->delete();

        try {
            $this->stock()->postAdjustment($adjustment->fresh());
            $this->fail('Expected a deleted item to be refused, not to crash.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('which has been deleted', $e->getMessage());
            $this->assertStringContainsString($adjustment->code, $e->getMessage());
        }

        // The atomic rollback is intact: nothing posted, nothing counted in.
        $this->assertSame(DocumentStatus::Approved, $adjustment->fresh()->status);
        $this->assertNull($adjustment->fresh()->posted_at);
        $this->assertSame(0.0, $this->balanceQty($this->a, $langka));
    }

    public function test_an_opname_posts_normally_while_its_item_is_alive(): void
    {
        $langka = $this->makeItem('Konektor RJ45 Lawas', ['unit' => 'pcs', 'last_price' => 2500]);
        $adjustment = $this->makeAdjustment($this->a, [[$langka, 40]], '2026-03-25');

        $this->stock()->postAdjustment($adjustment->fresh());

        // No warehouse history and no global average, so the last purchase price
        // values the surplus: 40 * 2.500 = 100.000.
        $this->assertNotNull($adjustment->fresh()->posted_at);
        $this->assertSame(40.0, $this->balanceQty($this->a, $langka));
        $this->assertSame(100000.0, $this->balanceValue($this->a, $langka));
    }

    public function test_the_endpoint_answers_422_rather_than_500_for_a_deleted_item(): void
    {
        // The user-visible half of the same defect: TransferController catches
        // LogicException, so the storeman finally gets a message instead of a
        // blank 500 he can do nothing with.
        $this->receiveStock($this->a, $this->kabel, 10, 1150000, '2026-03-01');
        $transfer = $this->makeTransfer($this->a, $this->b, [[$this->kabel, 10]], '2026-03-05');
        $this->stock()->sendTransfer($transfer);

        DB::table('inv_items')->where('id', $this->kabel->id)->update(['deleted_at' => now()]);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/inventory/transfers/{$transfer->id}/receive")
            ->assertStatus(422)
            ->assertJsonPath('message', "Transfer {$transfer->code} references item #{$this->kabel->id}, "
                .'which has been deleted; restore the item before receiving the goods.');
    }
}
