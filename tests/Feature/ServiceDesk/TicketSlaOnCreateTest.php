<?php

namespace Tests\Feature\ServiceDesk;

use App\Models\User;
use Modules\Crm\Models\Customer;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\ServiceDesk\Models\ServiceContract;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * A ticket created through the API carries its SLA deadline from the first
 * save (T3.5, ANALISIS-PROSES D1: "sla_due_at dihitung saat tiket dibuat").
 *
 * The column is svc_tickets.resolution_due_at — the RECAP's `sla_due_at` never
 * existed; TicketService::create → applySlaDueDates has written it since the
 * module's first migration, and the `ticket_sla` watcher (T3.2) reads exactly
 * this column. The SLA hours live on the maintenance contract (SlaService), so
 * the deadline is only ever set for a contract-bearing ticket: priority decides
 * the clock (critical = 24/7, otherwise Mon-Fri 08:00-17:00 Asia/Jakarta).
 * The shape below is TKT-202607-0003's: reported Sunday 5 Jul 2026 06:00 with a
 * 24-hour resolution SLA → Mon 9 h + Tue 9 h + Wed 6 h → Wed 8 Jul 14:00.
 */
class TicketSlaOnCreateTest extends ErpTestCase
{
    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang '.implode(' ', $permissions),
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function serviceContract(): ServiceContract
    {
        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);

        return ServiceContract::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Kontrak Pemeliharaan CCTV & Akses Kontrol RS Medika Husada',
            'period_start' => '2026-04-01',
            'period_end' => '2027-03-31',
            'contract_value' => 480_000_000,
            'sla_response_hours' => 4,
            'sla_resolution_hours' => 24,
            'status' => 'active',
        ]);
    }

    public function test_a_new_contract_ticket_has_its_resolution_deadline_from_the_first_save(): void
    {
        $contract = $this->serviceContract();

        $response = $this->actingAs($this->userWith('svc.create'))
            ->postJson('/api/servicedesk/tickets', [
                'service_contract_id' => $contract->id,
                'title' => 'PM CCTV Bulanan — 05/07/2026',
                'category' => 'preventive',
                'priority' => 'low',
                'channel' => 'system',
                'reported_at' => '2026-07-05 06:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.response_due_at', '2026-07-06T12:00:00+07:00')
            ->assertJsonPath('data.resolution_due_at', '2026-07-08T14:00:00+07:00');

        $this->assertDatabaseHas('svc_tickets', [
            'id' => $response->json('data.id'),
            'resolution_due_at' => '2026-07-08 14:00:00',
        ]);
    }

    /**
     * The boundary of the acceptance, stated rather than hidden: without a
     * maintenance contract there are no SLA hours to count, so the deadline is
     * NULL by design (SlaService) and the ticket is outside `ticket_sla`'s
     * scope — see "## Open questions" in RECAP-UX-PROSES-2026-09.md.
     */
    public function test_a_ticket_without_a_contract_has_no_deadline_by_design(): void
    {
        $customer = Customer::query()->create(['name' => 'CV Mitra Niaga', 'is_pkp' => false, 'status' => 'active']);

        $this->actingAs($this->userWith('svc.create'))
            ->postJson('/api/servicedesk/tickets', [
                'customer_id' => $customer->id,
                'title' => 'Kamera lobi mati',
                'category' => 'incident',
                'priority' => 'high',
                'reported_at' => '2026-07-05 06:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.resolution_due_at', null);
    }
}
