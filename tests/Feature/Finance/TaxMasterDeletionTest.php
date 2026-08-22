<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Modules\Finance\Models\Tax;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * DELETE finance/taxes/{tax} — master data that documents point at is not
 * deleted.
 *
 * fin_taxes rows are soft-deleted and ApBill::pphTax() is a plain belongsTo, so
 * tidying "PPh schemes we no longer use" out of Master Data › Pajak turned
 * every historic withholding booked against those rows into pphTax === null.
 * TaxExportService then reported each one as "Jenis PPh pada BIL/... belum
 * ditetapkan — pilih jenis pajaknya pada tagihan", which is impossible: an
 * approved bill cannot be edited. In the audit probe Rp 25.837.500 of PPh final
 * dropped out of the e-Bupot file for its masa with no remedy at all, and the
 * vendor's PPh credit went unreported.
 *
 * Same shape as AccountController::destroy(), which has always refused a COA
 * account carrying journal lines.
 */
class TaxMasterDeletionTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    public function test_a_tax_row_an_approved_bill_withholds_under_cannot_be_deleted(): void
    {
        $tax = $this->makePphFinalTax();
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->makeVendor(['is_subcontractor' => true])->id,
            'bill_date' => '2026-06-10',
            'description' => 'Opname subkon struktur',
            'dpp' => 975000000,
            'pph_tax_id' => $tax->id,
            'pph_amount' => 25837500,
        ]));

        $response = $this->actingAs($this->userWith(['fin.delete']), 'sanctum')
            ->deleteJson("/api/finance/taxes/{$tax->id}")
            ->assertStatus(422);

        $this->assertStringContainsString('masih dipakai 1 tagihan', $response->json('message'));
        $this->assertStringContainsString($bill->code, $response->json('message'));
        $this->assertNull($tax->fresh()->deleted_at);
    }

    /** A draft bill counts too — it will be approved, and the row must survive. */
    public function test_a_draft_bill_is_enough_to_hold_the_tax_row(): void
    {
        $tax = $this->makePph23Tax(2.0);
        $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-10',
            'description' => 'Jasa konsultan',
            'dpp' => 100000000,
            'pph_tax_id' => $tax->id,
            'pph_amount' => 2000000,
        ]);

        $this->actingAs($this->userWith(['fin.delete']), 'sanctum')
            ->deleteJson("/api/finance/taxes/{$tax->id}")
            ->assertStatus(422);

        $this->assertNull($tax->fresh()->deleted_at);
    }

    /**
     * A cancelled bill reports nothing — its journal is reversed and the e-Bupot
     * query never sees it — so it does not hold the row hostage.
     */
    public function test_a_tax_used_only_by_a_cancelled_bill_can_be_deleted(): void
    {
        $tax = $this->makePphFinalTax();
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->makeVendor(['is_subcontractor' => true])->id,
            'bill_date' => '2026-06-10',
            'description' => 'Opname subkon struktur',
            'dpp' => 975000000,
            'pph_tax_id' => $tax->id,
            'pph_amount' => 25837500,
        ]));
        $this->apBills()->cancel($bill, $this->financeApprover(), 'Opname salah nilai.');

        $this->actingAs($this->userWith(['fin.delete']), 'sanctum')
            ->deleteJson("/api/finance/taxes/{$tax->id}")
            ->assertOk();

        $this->assertNotNull(Tax::withTrashed()->find($tax->id)->deleted_at);
    }

    /** An unused row is still ordinary master data. */
    public function test_an_unused_tax_row_is_deleted(): void
    {
        $tax = $this->makePph23Tax(2.0);

        $this->actingAs($this->userWith(['fin.delete']), 'sanctum')
            ->deleteJson("/api/finance/taxes/{$tax->id}")
            ->assertOk();

        $this->assertNull(Tax::query()->find($tax->id));
        $this->assertNotNull(Tax::withTrashed()->find($tax->id)->deleted_at);
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
