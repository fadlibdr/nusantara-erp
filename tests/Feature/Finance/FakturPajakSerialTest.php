<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\TaxExportService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * One nomor seri faktur pajak, one invoice.
 *
 * DJP issues each serial exactly once. The field is free text, pre-filled by
 * nothing, and registerFakturPajak() checked only that the invoice was
 * approved — so a clerk copying the previous termin's number onto the next
 * invoice produced two FK records under one serial in the same e-Faktur file,
 * Rp 1.177.000.000 of PPN keluaran reported against a number issued for
 * Rp 1.067.000.000, with `blocked` still 0 and nothing on the export screen
 * counting or flagging the duplicate.
 */
class FakturPajakSerialTest extends ErpTestCase
{
    use FinanceFixtures;

    private const SERIAL = '010.000-26.00000001';

    private Customer $customer;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->customer = $this->makeCustomer(['npwp' => '01.234.567.8-011.000']);
        $this->contract = $this->makeContract($this->customer);
    }

    public function test_a_serial_another_invoice_already_holds_is_refused(): void
    {
        $first = $this->approvedInvoiceFor(100000000, '2026-02-05');
        $second = $this->approvedInvoiceFor(200000000, '2026-02-20');

        $this->arInvoices()->registerFakturPajak($first, self::SERIAL);

        try {
            $this->arInvoices()->registerFakturPajak($second, self::SERIAL);
            $this->fail('A duplicate faktur pajak serial should be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($first->code, $e->getMessage());
            $this->assertNull($second->fresh()->faktur_pajak_no);
        }

        // And the file the officer downloads carries one FK, not two.
        $export = app(TaxExportService::class)->eFaktur(2026, 2);
        $this->assertSame(1, $export['summary']['exported']);
        $this->assertSame(1, $export['summary']['blocked']);
    }

    /** Two invoices, two serials — the ordinary case stays ordinary. */
    public function test_two_invoices_with_their_own_serials_both_export(): void
    {
        $first = $this->approvedInvoiceFor(100000000, '2026-02-05');
        $second = $this->approvedInvoiceFor(200000000, '2026-02-20');

        $this->arInvoices()->registerFakturPajak($first, self::SERIAL);
        $this->arInvoices()->registerFakturPajak($second, '010.000-26.00000002');

        $export = app(TaxExportService::class)->eFaktur(2026, 2);

        $this->assertSame(2, $export['summary']['exported']);
        $this->assertSame(0, $export['summary']['blocked']);
    }

    /** Re-keying the same number on the same invoice is a correction, not a clash. */
    public function test_the_same_invoice_may_be_given_the_same_serial_again(): void
    {
        $invoice = $this->approvedInvoiceFor(100000000, '2026-02-05');

        $this->arInvoices()->registerFakturPajak($invoice, self::SERIAL);
        $this->arInvoices()->registerFakturPajak($invoice, self::SERIAL);

        $this->assertSame(self::SERIAL, $invoice->fresh()->faktur_pajak_no);
    }

    /**
     * A cancelled faktur is replaced by a nota pembatalan citing the same
     * serial, so the serial stays spent rather than returning to the pool.
     */
    public function test_a_cancelled_invoice_keeps_its_serial_reserved(): void
    {
        $cancelled = $this->approvedInvoiceFor(100000000, '2026-02-05');
        $this->arInvoices()->registerFakturPajak($cancelled, self::SERIAL);
        $this->arInvoices()->cancel($cancelled, $this->financeApprover(), 'Salah nilai termin.');

        $replacement = $this->approvedInvoiceFor(100000000, '2026-02-20');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sudah dipakai invoice');

        $this->arInvoices()->registerFakturPajak($replacement, self::SERIAL);
    }

    /** The API reports it on the field rather than as a bare 422 message. */
    public function test_the_endpoint_reports_the_clash_on_the_field(): void
    {
        $first = $this->approvedInvoiceFor(100000000, '2026-02-05');
        $second = $this->approvedInvoiceFor(200000000, '2026-02-20');
        $this->arInvoices()->registerFakturPajak($first, self::SERIAL);

        $user = $this->userWith(['fin.update']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/finance/ar-invoices/{$second->id}/faktur", ['faktur_pajak_no' => self::SERIAL])
            ->assertStatus(422)
            ->assertJsonValidationErrors('faktur_pajak_no');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/finance/ar-invoices/{$second->id}/faktur", ['faktur_pajak_no' => '010.000-26.00000002'])
            ->assertOk();

        $this->assertSame('010.000-26.00000002', $second->fresh()->faktur_pajak_no);
    }

    private function approvedInvoiceFor(float $dpp, string $date): ArInvoice
    {
        $invoice = $this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => $date,
            'description' => 'Penagihan termin',
            'dpp' => $dpp,
            'ppn_rate' => 11.0,
        ]);

        $approved = $this->approveInvoice($invoice);
        $this->assertSame(DocumentStatus::Approved, $approved->status);

        return $approved;
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna Uji',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
