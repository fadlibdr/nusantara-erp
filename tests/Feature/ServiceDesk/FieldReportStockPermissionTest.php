<?php

namespace Tests\Feature\ServiceDesk;

use App\Models\User;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\ServiceDesk\Enums\FieldReportStatus;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\Ticket;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * THE SIDE DOOR INTO THE WAREHOUSE.
 *
 * POST field-reports/{id}/acknowledge is a signature endpoint right up to the
 * moment the report lists spare parts, at which point FieldReportService::issueParts()
 * runs the full inventory posting inside the same transaction: stock leaves a
 * warehouse at moving average and a journal Dr 6-4100 / Cr 1-1400 hits the
 * general ledger. It was gated on permission:svc.update alone.
 *
 * The audit proved it end to end with the shipped account. teknisi@nusantara.test
 * holds svc.view/create/update plus inv.VIEW and nothing else — RoleSeeder gives
 * inv.post to `admin` only, and deliberately withholds it even from `warehouse`.
 * That login wrote a report, listed 30 x ITM-0004 CCTV Dome 4MP against WH-PUSAT,
 * signed it with a customer name it typed itself, and moved Rp 55.500.000 of
 * stock onto the P&L — while the SAME login is refused
 * POST inventory/issues/{id}/post with a 403.
 *
 * So the endpoint now asks for the right it exercises, and only when it exercises
 * it: a signature-only sign-off moves no stock and stays a technician's job.
 */
class FieldReportStockPermissionTest extends ErpTestCase
{
    use InventoryFixtures;

    /** Cross-module id: HrPayroll owns hr_employees, there is no FK to satisfy. */
    private const TECHNICIAN_ID = 7;

    private Warehouse $gudang;

    private Item $kamera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->gudang = $this->makeWarehouse('WH-PUSAT');
        $this->kamera = $this->makeItem('CCTV Dome 4MP', [
            'unit' => 'unit',
            'item_type' => ItemType::Sparepart,
        ]);

        // 30 units @ Rp 1.850.000 — the audit's WH-PUSAT position.
        $this->receiveStock($this->gudang, $this->kamera, 30, 1850000, '2026-06-01');
    }

    public function test_a_technician_without_the_inventory_posting_right_cannot_sign_off_a_parts_visit(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $this->actingAs($this->teknisi(), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/acknowledge", [
                'customer_sign_name' => 'Darto Prasetyo',
            ])
            ->assertForbidden();

        // 3 x 1.850.000 = Rp 5.550.000 did not leave the warehouse, no bon was
        // raised, and the report is still waiting for a signature.
        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
        $this->assertSame(0, Issue::query()->count());
        $this->assertSame(FieldReportStatus::Submitted, $report->fresh()->status);
        $this->assertNull($report->fresh()->customer_sign_name);
    }

    public function test_the_same_technician_can_still_sign_off_a_signature_only_visit(): void
    {
        // A visit that consumed no parts moves no stock and touches no ledger, so
        // demanding an inventory right for it would break the ordinary workflow
        // to close a hole that is not there.
        $report = $this->submittedReport();

        $this->actingAs($this->teknisi(), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/acknowledge", [
                'customer_sign_name' => 'Darto Prasetyo',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        $this->assertSame('Darto Prasetyo', $report->fresh()->customer_sign_name);
        $this->assertSame(0, Issue::query()->count());
    }

    public function test_a_login_holding_the_inventory_posting_right_signs_off_the_parts_visit(): void
    {
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $this->actingAs($this->supervisor(), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/acknowledge", [
                'customer_sign_name' => 'Darto Prasetyo',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');

        // The bridge is unchanged where it is allowed: 30 - 3 = 27 units, one
        // posted bon dated on the visit.
        $this->assertSame(27.0, $this->balanceQty($this->gudang, $this->kamera));
        $issue = Issue::query()->where('field_report_id', $report->id)->sole();
        $this->assertSame('2026-06-10', $issue->issue_date->toDateString());
    }

    public function test_the_service_desk_right_alone_is_still_required_for_the_signature(): void
    {
        // The floor did not move: inv.post is an ADDITIONAL demand, not a
        // replacement, so a warehouse login with no svc.update is still refused
        // by the route middleware before the request is ever authorised.
        $report = $this->submittedReport([[$this->kamera, 3]]);

        $this->actingAs($this->roleUser('gudang', ['inv.view', 'inv.post'], 'kepala-gudang@test.local'), 'sanctum')
            ->postJson("/api/servicedesk/field-reports/{$report->id}/acknowledge", [
                'customer_sign_name' => 'Darto Prasetyo',
            ])
            ->assertForbidden();

        $this->assertSame(30.0, $this->balanceQty($this->gudang, $this->kamera));
    }

    // ----------------------------------------------------------------- fixtures

    /**
     * The shipped technician: svc view/create/update plus inv.VIEW, exactly as
     * Modules/Iam RoleSeeder seeds it.
     */
    private function teknisi(): User
    {
        return $this->roleUser(
            'teknisi',
            ['svc.view', 'svc.create', 'svc.update', 'inv.view'],
            'teknisi@test.local',
        );
    }

    /**
     * Someone who may both sign a report off and move stock. inv.post is
     * admin-only in the shipped role matrix, which is why this is the privilege
     * the finding wanted made VISIBLE rather than smuggled in through svc.update.
     */
    private function supervisor(): User
    {
        return $this->roleUser(
            'supervisor-servis',
            ['svc.view', 'svc.create', 'svc.update', 'inv.view', 'inv.post'],
            'supervisor@test.local',
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function roleUser(string $name, array $permissions, string $email): User
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::findOrCreate($name, 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Joko Susilo',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<int, array{0: Item, 1: float}>  $parts
     */
    private function submittedReport(array $parts = []): FieldReport
    {
        $ticket = Ticket::create([
            'customer_id' => 1, // crm_customers.id (cross-module, no FK)
            'title' => 'Kamera lobi mati total',
            'priority' => 'high',
            'reported_at' => '2026-06-09 08:00:00',
        ]);

        $report = FieldReport::create([
            'ticket_id' => $ticket->id,
            'report_date' => '2026-06-10',
            'technician_employee_id' => self::TECHNICIAN_ID,
            'warehouse_id' => $this->gudang->id,
            'findings' => '3 unit kamera dome lobi mati total.',
            'actions_taken' => 'Penggantian 3 unit CCTV Dome 4MP.',
            'status' => FieldReportStatus::Submitted,
        ]);

        foreach ($parts as [$item, $qty]) {
            $report->parts()->create(['item_id' => $item->id, 'qty' => $qty]);
        }

        return $report->refresh();
    }
}
