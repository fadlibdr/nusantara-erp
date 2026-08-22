<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET core/dashboard/summary — the dashboard's money tiles, summed in SQL.
 *
 * dashboard.js used to fetch page 1 (per_page 100) of projects, AR invoices
 * and AP bills and reduce the tile numbers client-side: from row 101 on, every
 * number was silently too small. These tests pin the server sums to the exact
 * semantics the tiles always claimed — approved documents only, outstanding =
 * total − amount_paid — and the calendar-style rule that each block appears
 * only for callers holding that module's .view permission.
 */
class DashboardSummaryTest extends ErpTestCase
{
    // -------------------------------------------------------------- fixtures

    private function actAsHolderOf(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang Izin',
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function project(string $code, string $status, float $value, ?int $managerId = null): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => "Proyek {$code}",
            'type' => 'construction',
            'status' => $status,
            'contract_value' => $value,
            // hr_employees semantics, cross-module without DB constraint — a
            // bare id is exactly what the production column holds.
            'project_manager_id' => $managerId,
        ]);
    }

    private function invoice(string $status, float $total, float $paid): ArInvoice
    {
        $customer = Customer::query()->firstOrCreate(
            ['name' => 'PT Graha Sentosa Propertindo'],
            ['is_pkp' => true, 'status' => 'active'],
        );
        $contract = Contract::query()->firstOrCreate(
            ['title' => 'Gedung Kantor Graha Sentosa'],
            [
                'customer_id' => $customer->id,
                'scope_type' => 'construction',
                'value' => 48_500_000_000,
                'status' => DocumentStatus::Approved,
            ],
        );

        return ArInvoice::query()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'description' => 'Penagihan termin uji',
            'dpp' => $total,
            'total' => $total,
            'amount_paid' => $paid,
            'terbilang' => 'Terbilang uji',
            'status' => $status,
        ]);
    }

    private function bill(string $status, float $payable, float $paid): ApBill
    {
        $vendor = Vendor::query()->firstOrCreate(
            ['code' => 'VND-0001'],
            [
                'name' => 'PT Semen Distribusi Utama',
                'is_pkp' => true,
                'is_subcontractor' => false,
                'classification' => 'material',
                'status' => 'active',
            ],
        );

        return ApBill::query()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'description' => 'Tagihan vendor uji',
            'dpp' => $payable,
            'total_payable' => $payable,
            'amount_paid' => $paid,
            'vendor_invoice_no' => 'INV-VND-1',
            'status' => $status,
        ]);
    }

    // ------------------------------------------------------------------ sums

    public function test_summary_sums_approved_money_and_counts_open_documents(): void
    {
        $this->actAsHolderOf('prj.view', 'fin.view');

        // Tile semantics: active + finishing count; preparation stays out.
        $this->project('PRJ-2026-001', 'active', 48_500_000_000);
        $this->project('PRJ-2026-002', 'finishing', 9_800_000_000);
        $this->project('PRJ-2026-003', 'preparation', 1_000_000_000);

        // Approved-and-unpaid is outstanding; approved-and-paid contributes
        // Rp 0 and is not "open"; draft and cancelled never count at all.
        $this->invoice('approved', 150_000_000, 50_000_000);
        $this->invoice('approved', 80_000_000, 80_000_000);
        $this->invoice('draft', 999_000_000, 0);
        $this->invoice('cancelled', 777_000_000, 0);

        $this->bill('approved', 60_000_000, 10_000_000);
        $this->bill('draft', 500_000_000, 0);

        $response = $this->getJson('api/core/dashboard/summary')->assertOk();

        $this->assertSame(2, $response->json('data.projects.active_count'));
        $this->assertSame(58_300_000_000.0, (float) $response->json('data.projects.contract_value'));

        $this->assertSame(100_000_000.0, (float) $response->json('data.ar_invoices.outstanding'));
        $this->assertSame(1, $response->json('data.ar_invoices.open_count'));

        $this->assertSame(50_000_000.0, (float) $response->json('data.ap_bills.outstanding'));
        $this->assertSame(1, $response->json('data.ap_bills.open_count'));
    }

    // ------------------------------------------------------------ permission

    public function test_each_block_appears_only_for_its_view_permission(): void
    {
        $this->project('PRJ-2026-001', 'active', 48_500_000_000);
        $this->invoice('approved', 150_000_000, 0);

        // fin.view alone: the money a finance officer may read, and NOT the
        // project block — the same "seeing is reading" rule as the calendar.
        $this->actAsHolderOf('fin.view');
        $fin = $this->getJson('api/core/dashboard/summary')->assertOk()->json('data');

        $this->assertArrayHasKey('ar_invoices', $fin);
        $this->assertArrayHasKey('ap_bills', $fin);
        $this->assertArrayNotHasKey('projects', $fin);

        // prj.view alone: the mirror image.
        $this->actAsHolderOf('prj.view');
        $prj = $this->getJson('api/core/dashboard/summary')->assertOk()->json('data');

        $this->assertArrayHasKey('projects', $prj);
        $this->assertArrayNotHasKey('ar_invoices', $prj);
        $this->assertArrayNotHasKey('ap_bills', $prj);
    }

    // -------------------------------------------------------------- 'mine'

    public function test_mine_limits_the_project_block_to_projects_managed_by_the_caller(): void
    {
        $user = $this->actAsHolderOf('prj.view');
        $user->forceFill(['employee_id' => 42])->save();

        $this->project('PRJ-2026-001', 'active', 48_500_000_000, managerId: 42);
        $this->project('PRJ-2026-002', 'active', 9_800_000_000, managerId: 77);

        $data = $this->getJson('api/core/dashboard/summary?mine=1')->assertOk()->json('data');

        $this->assertSame(1, $data['projects']['active_count']);
        $this->assertSame(48_500_000_000.0, (float) $data['projects']['contract_value']);
    }

    public function test_mine_is_honestly_empty_for_a_user_without_an_employee_link(): void
    {
        // An account that maps to no employee manages no projects. Returning
        // ALL projects here would make the toggle a lie precisely for admin
        // accounts — the ones most likely to flip it first.
        $this->actAsHolderOf('prj.view');
        $this->project('PRJ-2026-001', 'active', 48_500_000_000, managerId: 42);

        $data = $this->getJson('api/core/dashboard/summary?mine=1')->assertOk()->json('data');

        $this->assertSame(0, $data['projects']['active_count']);
        $this->assertSame(0.0, (float) $data['projects']['contract_value']);
    }
}
