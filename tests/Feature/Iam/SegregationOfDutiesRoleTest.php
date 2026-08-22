<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Database\Seeders\RoleSeeder;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Feature\HrPayroll\PayrollFixtures;

/**
 * The finding this package closes, asserted against the seeded roles.
 *
 * Before the split, `finance` held create + approve + post on every finance
 * document, so one login could raise a vendor bill to a vendor of its own
 * choosing, approve it and disburse it. Roles are the OUTER ring of the control
 * — maker-checker is the inner one — and losing either quietly puts the whole
 * one-person path back.
 *
 * `hr` was the same bundle with a different prefix and is asserted here too:
 * its approve IS its posting (PayrollRunController::approve books the run to
 * the ledger in the same transaction), so before its split one login could
 * raise a salary and put Rp 196.270.346,83 of it in the books the moment
 * maker-checker was unticked — one setting flip, where finance had the role
 * split as a second, independent ring.
 */
class SegregationOfDutiesRoleTest extends ErpTestCase
{
    use PayrollFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->firstOrFail();
    }

    public function test_the_finance_role_can_prepare_and_pay_but_not_approve(): void
    {
        $finance = $this->role('finance');

        foreach (['fin.view', 'fin.create', 'fin.update', 'fin.delete', 'fin.post'] as $permission) {
            $this->assertTrue(
                $finance->hasPermissionTo($permission),
                "finance must keep {$permission}: it still prepares and disburses.",
            );
        }

        $this->assertFalse(
            $finance->hasPermissionTo('fin.approve'),
            'create + approve + post in one role is the one-person fraud path an auditor calls a material weakness.',
        );
    }

    public function test_the_finance_manager_role_can_approve_but_cannot_raise_a_document(): void
    {
        $manager = $this->role('finance-manager');

        $this->assertTrue($manager->hasPermissionTo('fin.approve'));
        $this->assertTrue($manager->hasPermissionTo('fin.view'));

        foreach (['fin.create', 'fin.update', 'fin.delete', 'fin.post'] as $permission) {
            $this->assertFalse(
                $manager->hasPermissionTo($permission),
                "finance-manager must not hold {$permission}, or the second pair of eyes is the first pair again.",
            );
        }

        // Enough context to judge a bill without being able to raise one.
        foreach (['crm.view', 'prc.view', 'scm.view'] as $permission) {
            $this->assertTrue($manager->hasPermissionTo($permission));
        }
    }

    public function test_the_director_and_the_admin_can_still_approve_finance_documents(): void
    {
        $this->assertTrue($this->role('direktur')->hasPermissionTo('fin.approve'));
        $this->assertTrue($this->role('admin')->hasPermissionTo('fin.approve'));
    }

    public function test_a_finance_user_is_refused_the_approve_endpoint(): void
    {
        $bill = ApBill::query()->create([
            'vendor_id' => Vendor::query()->create([
                'name' => 'PT Semen Distribusi Utama',
                'classification' => 'material',
                'is_pkp' => true,
                'is_subcontractor' => false,
                'payment_term_days' => 30,
                'status' => 'active',
            ])->id,
            'bill_date' => '2026-03-10',
            'due_date' => '2026-04-09',
            'description' => 'Tagihan vendor',
            'vendor_invoice_no' => 'INV-2026-0001',
            'dpp' => 100000000,
            'total_payable' => 100000000,
            'status' => DocumentStatus::Submitted,
        ]);

        Sanctum::actingAs($this->userWithRole('finance', 'dewi@test.local'));

        $this->postJson("/api/finance/ap-bills/{$bill->id}/approve")->assertForbidden();

        // …while the manager approves the very same bill. The control is a
        // reassignment of the duty, not the loss of it.
        Sanctum::actingAs($this->userWithRole('finance-manager', 'ratna@test.local'));

        $this->postJson("/api/finance/ap-bills/{$bill->id}/approve")->assertOk();

        $this->assertSame(DocumentStatus::Approved, $bill->fresh()->status);
    }

    // ------------------------------------------------------------ payroll (hr)

    public function test_the_hr_role_can_prepare_payroll_but_not_approve(): void
    {
        $hr = $this->role('hr');

        foreach (['hr.view', 'hr.create', 'hr.update', 'hr.delete', 'hr.post'] as $permission) {
            $this->assertTrue(
                $hr->hasPermissionTo($permission),
                "hr must keep {$permission}: it still prepares, calculates and submits payroll.",
            );
        }

        $this->assertFalse(
            $hr->hasPermissionTo('hr.approve'),
            'hr with create + approve is one settings flip away from raising and booking its own salary.',
        );
    }

    public function test_the_director_and_the_admin_still_approve_payroll(): void
    {
        $this->assertTrue($this->role('direktur')->hasPermissionTo('hr.approve'));
        $this->assertTrue($this->role('admin')->hasPermissionTo('hr.approve'));
    }

    public function test_an_hr_user_is_refused_the_payroll_approve_endpoint_while_the_director_approves_it(): void
    {
        $hrUser = $this->userWithRole('hr', 'siti@test.local');

        // 8.000.000 gross, calculated and submitted by hr — the part of the
        // flow the role must keep.
        $this->makeEmployee(['base_salary' => 8_000_000]);
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);
        $run->submit($hrUser);

        Sanctum::actingAs($hrUser);
        $this->postJson("/api/hr/payroll-runs/{$run->id}/approve")->assertForbidden();
        $this->assertSame(DocumentStatus::Submitted, $run->fresh()->status);

        // …and the demo stays walkable: direktur@nusantara.test is the seeded
        // login that approves (and thereby posts) what Siti submitted.
        Sanctum::actingAs($this->userWithRole('direktur', 'budi@test.local'));
        $this->postJson("/api/hr/payroll-runs/{$run->id}/approve")->assertOk();

        $this->assertSame(DocumentStatus::Approved, $run->fresh()->status);
    }

    /**
     * RoleSeeder only shapes fresh installs; erp1's roles were seeded before
     * the split, so the migration has to do the same surgery there. This
     * recreates that pre-split state and runs the migration against it.
     */
    public function test_the_migration_strips_hr_approve_from_an_already_provisioned_hr_role(): void
    {
        $this->role('hr')->givePermissionTo('hr.approve');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($this->role('hr')->hasPermissionTo('hr.approve'));

        $migration = require base_path(
            'Modules/Iam/Database/Migrations/2026_08_01_000230_split_hr_approve_out_of_the_hr_role.php',
        );
        $migration->up();

        $this->assertFalse($this->role('hr')->hasPermissionTo('hr.approve'));
        // The duty is reassigned, not lost: the approver profile keeps it.
        $this->assertTrue($this->role('direktur')->hasPermissionTo('hr.approve'));
    }

    // --------------------------------------------- director-level approvals

    public function test_only_the_director_and_the_admin_hold_director_level_approvals(): void
    {
        foreach (['prc.approve-director', 'scm.approve-director'] as $permission) {
            $this->assertTrue($this->role('direktur')->hasPermissionTo($permission));
            $this->assertTrue($this->role('admin')->hasPermissionTo($permission));
            // The ordinary buyer approves ordinary POs; a Rp 6,5 miliar SPK is
            // exactly what their approve permission must NOT be enough for.
            $this->assertFalse($this->role('procurement')->hasPermissionTo($permission));
            $this->assertFalse($this->role('project-manager')->hasPermissionTo($permission));
        }
    }

    private function userWithRole(string $role, string $email): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
