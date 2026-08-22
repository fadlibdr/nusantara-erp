<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\Erp;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * THE INVENTORY ACCOUNTING METHOD IS AN ELECTION, NOT A CHECKBOX — audit A2.
 *
 * accounting.perpetual_inventory used to sit on the settings screen next to the
 * PPN rate: an operator-editable boolean, read live when a goods receipt posted
 * and read again when the vendor bill was approved. One flip corrupted the
 * ledger in whichever direction it was flipped, measured on a single 6.200.000
 * purchase:
 *
 *   ON at receipt, OFF later    1-1400 keeps 6.200.000 against a stock
 *       sub-ledger of 0,00 while 5-1100 and the project realisasi stay 0,00 —
 *       the issue that would have relieved persediaan no longer posts, so the
 *       material is expensed nowhere, ever.
 *   OFF at receipt, ON later    the bill already expensed the purchase; the
 *       issue then debits 5-1100 a second time and credits a persediaan account
 *       that was never debited: 5-1100 = 12.400.000 for a 6.200.000 purchase
 *       and 1-1400 = -6.200.000.
 *
 * Neither is a defect the engine can prevent — each posting was right under the
 * method in force when it was made. A genuine change of method needs a stock
 * revaluation booked by an accountant at a fiscal-period boundary. So the key
 * was withdrawn from the registry: it lives in config/erp.php alone, changing it
 * takes a deploy, and `php artisan erp:inventory-method-check` says first
 * whether a change would strand anything.
 *
 * This test pins the four properties that keep it that way — it is not in the
 * registry, the service refuses to write it, the endpoint refuses to store it,
 * and the safety check actually answers the question it was asked.
 */
class AccountingMethodElectionTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const KEY = 'accounting.perpetual_inventory';

    private const COMMAND = 'erp:inventory-method-check';

    /** 100 zak * 62.000 = 6.200.000 */
    private const RECEIPT_QTY = 100.0;

    private const RECEIPT_UNIT_COST = 62000.0;

    private const RECEIPT_VALUE = 6200000.0;

    private Warehouse $pusat;

    private Item $semen;

    private Vendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Portland 50kg');
        $this->supplier = $this->makeVendor();
    }

    private function settings(): SettingService
    {
        return app(SettingService::class);
    }

    // ------------------------------------------------------------ it is not on the screen

    public function test_the_registry_does_not_advertise_the_inventory_accounting_method(): void
    {
        $editable = $this->settings()->editableKeys();

        $this->assertArrayNotHasKey(self::KEY, $editable);

        // Withdrawn deliberately, not lost: config/erp.php still defines it and
        // the engine still reads it, which is exactly what makes it an
        // install-time constant rather than a dead key.
        $this->assertTrue(config()->has('erp.'.self::KEY));
        $this->assertTrue(Erp::setting(self::KEY, true));

        // Nothing in the accounting group is a boolean any more — every key on
        // that screen is a COA account code. Scoped to that group on purpose:
        // the guard is against an accounting-method election creeping back onto
        // a screen that posts journals, not against booleans in general, which
        // are ordinary elsewhere (notifications.email_enabled is one).
        foreach ($this->settings()->definitions()['accounting']['settings'] as $definition) {
            $this->assertNotSame(
                'boolean',
                $definition['type'],
                "Setting [{$definition['key']}] is editable and boolean on the accounting screen.",
            );
        }

        // And the screen payload cannot offer what the registry does not hold.
        $offered = collect($this->settings()->overview())
            ->flatMap(fn (array $group): array => $group['settings'])
            ->pluck('key')
            ->all();

        $this->assertNotContains(self::KEY, $offered);
    }

    // ------------------------------------------------------------ the service refuses to write it

    public function test_the_service_refuses_to_store_the_inventory_accounting_method(): void
    {
        try {
            $this->settings()->set(self::KEY, false);
            $this->fail('Expected the accounting method to be unwritable through the service.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(self::KEY, $e->getMessage());
            $this->assertStringContainsString('not editable', $e->getMessage());
        }

        $this->assertDatabaseCount('core_settings', 0);
        $this->assertFalse($this->settings()->isOverridden(self::KEY));
        $this->assertTrue(Erp::setting(self::KEY, true)); // still the shipped election
    }

    public function test_a_batch_carrying_the_method_stores_none_of_its_other_keys(): void
    {
        // setMany validates every key before writing any, so a payload that
        // smuggles the election in beside a legitimate rate applies nothing at
        // all — no half-applied batch, and above all no method change.
        try {
            $this->settings()->setMany([
                'tax.ppn_rate' => 12.0,
                self::KEY => false,
            ]);
            $this->fail('Expected the batch to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(self::KEY, $e->getMessage());
        }

        $this->assertDatabaseCount('core_settings', 0);
        $this->assertSame(11.0, Erp::float('tax.ppn_rate'));
        $this->assertTrue(Erp::setting(self::KEY, true));
    }

    // ------------------------------------------------------------ the endpoint refuses to store it

    public function test_the_settings_endpoint_rejects_the_method_and_persists_nothing(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        $response = $this->putJson('/api/core/settings', [
            'settings' => [self::KEY => false],
        ]);

        $response->assertStatus(422);

        $errors = (array) $response->json('errors');
        $this->assertArrayHasKey('settings.'.self::KEY, $errors);

        // The operator is told the truth — the parameter exists, it is fixed at
        // installation — rather than "tidak dikenal", which would send them
        // hunting for a spelling mistake.
        $message = implode(' ', $errors['settings.'.self::KEY]);
        $this->assertStringContainsString('config/erp.php', $message);
        $this->assertStringContainsString('deploy', $message);

        $this->assertDatabaseCount('core_settings', 0);
        $this->assertTrue(Erp::setting(self::KEY, true));
    }

    public function test_a_valid_parameter_sent_alongside_the_method_is_not_persisted_either(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        $this->putJson('/api/core/settings', [
            'settings' => [
                'tax.ppn_rate' => 12.0,
                self::KEY => false,
            ],
        ])->assertStatus(422);

        // One rejected key rejects the whole batch: the PPN rate is untouched
        // and, crucially, no row for the election exists either.
        $this->assertDatabaseCount('core_settings', 0);
        $this->assertNull(Setting::query()->where('key', self::KEY)->first());
        $this->assertSame(11.0, Erp::float('tax.ppn_rate'));
        $this->assertTrue(Erp::setting(self::KEY, true));
    }

    // ------------------------------------------------------------ the safety check answers the question

    public function test_the_safety_check_reports_safe_when_nothing_would_be_stranded(): void
    {
        // An installation with an open fiscal calendar but no stock documents,
        // no stock on hand and no stored override: a change of method would
        // strand nothing, so the command says so and exits zero.
        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SAFE', $output);
        $this->assertStringNotContainsString('UNSAFE', $output);
        $this->assertStringContainsString('Method in force : PERPETUAL', $output);
    }

    public function test_the_safety_check_reports_unsafe_while_a_posted_receipt_is_uncleared(): void
    {
        // One delivery from a known vendor, posted, not yet invoiced: the
        // receipt credited 2-1600 with 6.200.000 and the bill that debits it
        // back out does not exist yet. That chain straddles a method change.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::RECEIPT_QTY, self::RECEIPT_UNIT_COST]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        // 100 * 62.000 = 6.200.000 recorded as clearable, nothing cleared.
        $this->assertSame('2-1600', $grn->fresh()->gl_clearing_account);
        $this->assertSame(self::RECEIPT_VALUE, $grn->fresh()->recordedClearingAmount());

        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('UNSAFE', $output);
        $this->assertStringContainsString('1 receipt(s) carry Rp 6.200.000,00 of clearing no bill has settled', $output);
        $this->assertStringContainsString($grn->code, $output);

        // It reports; it never repairs. The election is still what config says.
        $this->assertTrue(Erp::setting(self::KEY, true));
        $this->assertDatabaseCount('core_settings', 0);
    }

    public function test_settling_the_bill_removes_that_blocker_from_the_safety_check(): void
    {
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::RECEIPT_QTY, self::RECEIPT_UNIT_COST]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        Artisan::call(self::COMMAND);
        $this->assertStringContainsString('of clearing no bill has settled', Artisan::output());

        // The bill against that receipt debits back exactly the 6.200.000 it
        // credited, so the chain is finished.
        $bill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $grn->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertSame(self::RECEIPT_VALUE, (float) $bill->gl_cleared_amount);

        $exitCode = Artisan::call(self::COMMAND);
        $output = Artisan::output();

        $this->assertStringNotContainsString('of clearing no bill has settled', $output);

        // Still unsafe, and rightly: 6.200.000 of stock is on hand, and that
        // value is what the two methods disagree about. It can only be revalued,
        // never worked off by finishing documents — which is precisely why this
        // is not a checkbox.
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Stock on hand still carries value', $output);
        $this->assertStringContainsString('Rp 6.200.000,00', $output);
    }
}
