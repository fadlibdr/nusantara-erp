<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Core\Support\WatchedDeadlines;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET core/deadlines — the tenggat screen's live truth.
 *
 * The endpoint re-runs the same scan the daily command runs, filtered to the
 * permissions the CALLER holds: a notification can be read and forgotten, but
 * an unresolved deadline stays on this screen. Like search, "nothing here"
 * and "nothing you may see" must be the same answer.
 */
class DeadlineApiTest extends ErpTestCase
{
    private const TODAY = '2026-08-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

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

    /** Two findings for two different permissions: prc.update and hr.update. */
    private function seedTwoModulesOfTrouble(): void
    {
        $vendor = Vendor::query()->create([
            'name' => 'PT Sumber Makmur Elektrindo',
            'is_subcontractor' => false,
            'classification' => 'material',
            'status' => 'active',
        ]);
        PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-02-10',
            'expected_date' => '2026-03-01',
            'total' => 232_500_000,
            'status' => 'approved',
        ]);

        Employee::query()->create([
            'code' => 'EMP-0007',
            'name' => 'Joko Susilo',
            'nik_ktp' => str_pad('7', 16, '3', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2025-01-01',
            'employment_type' => 'kontrak',
            'position' => 'Pelaksana',
            'department' => 'proyek',
            'base_salary' => 0,
            'status' => 'active',
        ]);
    }

    public function test_the_endpoint_returns_only_entries_whose_permission_the_caller_holds(): void
    {
        $this->seedTwoModulesOfTrouble();
        $this->actAsHolderOf('prc.update');

        $response = $this->getJson('/api/core/deadlines')->assertOk();

        $keys = array_column($response->json('data'), 'key');
        $this->assertContains('po_expected', $keys);
        $this->assertNotContains('pkwt_end', $keys);

        $po = collect($response->json('data'))->firstWhere('key', 'po_expected');
        $this->assertSame('lewat', $po['tier']);
        $this->assertSame(1, $po['count']);
        $this->assertSame('2026-03-01', $po['items'][0]['date']);
        $this->assertSame(-153, $po['items'][0]['days']);
        $this->assertSame(self::TODAY, $response->json('meta.today'));
    }

    public function test_an_hr_caller_sees_the_pkwt_alarm_and_not_procurement(): void
    {
        $this->seedTwoModulesOfTrouble();
        $this->actAsHolderOf('hr.update');

        $keys = array_column($this->getJson('/api/core/deadlines')->assertOk()->json('data'), 'key');

        $this->assertContains('pkwt_end', $keys);
        $this->assertNotContains('po_expected', $keys);
    }

    public function test_a_caller_with_no_relevant_permission_gets_an_empty_list(): void
    {
        $this->seedTwoModulesOfTrouble();
        $this->actAsHolderOf('inv.view');

        $this->getJson('/api/core/deadlines')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => [
                'today' => self::TODAY,
                // Adding a watcher is adding an array entry — this must not
                // be a second place to update.
                'checked' => count(WatchedDeadlines::entries()),
                'skipped' => 0,
            ]]);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/core/deadlines')->assertUnauthorized();
    }
}
