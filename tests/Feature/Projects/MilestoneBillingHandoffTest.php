<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Notification;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\MilestoneService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Serah terima PM→Finance: milestone tercapai ⇒ termin siap ditagih.
 *
 * The live database is the case for this test. Milestone "Progres fisik 50% —
 * syarat penagihan Termin 2" of CTR/2026/I/0001 was recorded achieved on
 * 27-03-2026. The termin it releases is worth Rp 14.550.000.000. On 31-07-2026 —
 * four months and one closed quarter later — billed_at was still NULL. No
 * feature had failed: the handoff between the project manager who ticks the box
 * and the finance staffer who raises the invoice was a WhatsApp message, and one
 * message was not sent.
 *
 * What is guarded here is the alert being worth reading. An achievement alert
 * that repeats itself on every later edit, or that names a termin already
 * invoiced, trains its audience to ignore the bell — and the next Rp 14,55
 * miliar goes missing exactly the same way.
 */
class MilestoneBillingHandoffTest extends ErpTestCase
{
    private MilestoneService $service;

    private Contract $contract;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->service = app(MilestoneService::class);

        $this->contract = $this->approvedContract('Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)', 48_500_000_000);
        $this->project = $this->projectFor($this->contract);
    }

    // -------------------------------------------------------------- fixtures

    private function approvedContract(string $title, float $value): Contract
    {
        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);

        return Contract::query()->create([
            'customer_id' => $customer->id,
            'title' => $title,
            'scope_type' => 'construction',
            'value' => $value,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'status' => DocumentStatus::Approved,
        ]);
    }

    private function projectFor(Contract $contract): Project
    {
        return Project::query()->create([
            'name' => 'Proyek '.$contract->title,
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    /** Termin 2 of the live contract, to the rupiah: 30% of Rp 48,5 M. */
    private function termin(array $attributes = []): ContractTermin
    {
        return ContractTermin::query()->create(array_merge([
            'contract_id' => $this->contract->id,
            'termin_no' => 2,
            'name' => 'Progress 50%',
            'percent' => 30,
            'amount' => 14_550_000_000,
        ], $attributes));
    }

    private function milestone(array $attributes = []): Milestone
    {
        return $this->service->create(array_merge([
            'project_id' => $this->project->id,
            'name' => 'Progres fisik 50% — syarat penagihan Termin 2',
            'due_date' => '2026-04-15',
        ], $attributes));
    }

    /** Somebody who can actually raise the invoice, and nothing else. */
    private function financeStaff(string $name = 'Staf Penagihan'): User
    {
        $role = Role::findOrCreate('penagihan', 'web');
        $role->givePermissionTo('fin.create');

        /** @var User $user */
        $user = User::query()->create([
            'name' => $name,
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function inboxOf(User $user)
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('event', Notification::SYSTEM)
            ->get();
    }

    // ------------------------------------------------- the handoff that fires

    /**
     * THE ONE THAT WOULD HAVE SAVED THE Rp 14,55 MILIAR. Achieving the milestone
     * has to reach the people who invoice, by itself, with no second step.
     */
    public function test_achieving_a_billing_milestone_tells_whoever_raises_invoices(): void
    {
        $staff = $this->financeStaff();
        $milestone = $this->milestone(['termin_id' => $this->termin()->id]);

        $this->assertCount(0, $this->inboxOf($staff), 'a milestone that is merely scheduled is not news');

        $this->service->update($milestone, ['achieved_date' => '2026-03-27']);

        $this->assertCount(1, $this->inboxOf($staff));
    }

    /**
     * A notification that says "something happened" makes the reader open three
     * screens to find out whether it matters. Contract, termin number and the
     * rupiah value have to be legible from the bell itself.
     */
    public function test_the_alert_names_the_contract_the_termin_and_the_rupiah(): void
    {
        $staff = $this->financeStaff();
        $this->service->update(
            $this->milestone(['termin_id' => $this->termin()->id]),
            ['achieved_date' => '2026-03-27'],
        );

        $alert = $this->inboxOf($staff)->first();

        // 30% of Rp 48.500.000.000 = Rp 14.550.000.000.
        $this->assertStringContainsString($this->contract->code, $alert->title);
        $this->assertStringContainsString('Termin 2', $alert->title);
        $this->assertStringContainsString('Rp 14.550.000.000', $alert->title);
        $this->assertStringContainsString('27-03-2026', $alert->body);
        $this->assertStringContainsString('Progress 50%', $alert->body);
        $this->assertSame("#/d/crm/contracts/{$this->contract->id}", $alert->link);
    }

    /** A milestone backfilled as already achieved is still the first telling. */
    public function test_a_milestone_created_already_achieved_still_announces(): void
    {
        $staff = $this->financeStaff();

        $this->milestone([
            'termin_id' => $this->termin()->id,
            'achieved_date' => '2026-03-27',
        ]);

        $this->assertCount(1, $this->inboxOf($staff));
    }

    // ----------------------------------------------------- the quiet it keeps

    /**
     * THE GUARD THAT MATTERS MOST. Renaming the milestone, fixing a typo in the
     * notes, moving its due date — none of that is news, and an alert that
     * repeats on every edit is an alert that gets muted, taking the next real
     * one with it.
     */
    public function test_a_later_edit_does_not_announce_the_same_milestone_again(): void
    {
        $staff = $this->financeStaff();
        $milestone = $this->milestone(['termin_id' => $this->termin()->id]);

        $this->service->update($milestone, ['achieved_date' => '2026-03-27']);

        // Read, as a working inbox would be — the dedup on unread rows must not
        // be what is doing the work here.
        Notification::query()->update(['read_at' => now()]);

        $this->service->update($milestone->refresh(), ['notes' => 'BAP progres diajukan ke MK.']);
        $this->service->update($milestone->refresh(), ['name' => 'Progres fisik 50% (revisi)']);
        $this->service->update($milestone->refresh(), ['achieved_date' => '2026-03-28']);

        $this->assertCount(1, $this->inboxOf($staff), 'one achievement, one alert');
    }

    /**
     * The invoice sometimes goes out before anyone records the milestone. Telling
     * finance to bill what they have already billed is how a queue loses its
     * credibility.
     */
    public function test_an_already_billed_termin_is_never_announced(): void
    {
        $staff = $this->financeStaff();
        $termin = $this->termin(['billed_at' => '2026-03-30']);

        $this->service->update(
            $this->milestone(['termin_id' => $termin->id]),
            ['achieved_date' => '2026-03-27'],
        );

        $this->assertCount(0, $this->inboxOf($staff));
    }

    /** Most milestones are schedule markers with no money behind them. */
    public function test_a_milestone_without_a_termin_stays_inside_the_project(): void
    {
        $staff = $this->financeStaff();

        $this->service->update(
            $this->milestone(['name' => 'Mobilisasi alat berat']),
            ['achieved_date' => '2026-02-01'],
        );

        $this->assertCount(0, $this->inboxOf($staff));
    }

    /** Un-achieving a milestone is a correction, not an event. */
    public function test_clearing_the_achieved_date_announces_nothing(): void
    {
        $staff = $this->financeStaff();
        $milestone = $this->milestone([
            'termin_id' => $this->termin()->id,
            'achieved_date' => '2026-03-27',
        ]);

        Notification::query()->delete();

        $this->service->update($milestone, ['achieved_date' => null]);

        $this->assertCount(0, $this->inboxOf($staff));
    }

    /**
     * The alert is a side effect of recording progress, and a side effect may
     * never take down the thing it reports on. Nobody holds fin.create here, so
     * delivery has no recipients at all.
     */
    public function test_recording_the_achievement_survives_having_nobody_to_tell(): void
    {
        $milestone = $this->milestone(['termin_id' => $this->termin()->id]);

        $this->service->update($milestone, ['achieved_date' => '2026-03-27']);

        $this->assertSame('2026-03-27', $milestone->refresh()->achieved_date->toDateString());
    }

    // ------------------------------------------------- the cross-contract hole

    /**
     * termin_id was validated as 'integer|min:1' — an unchecked foreign key
     * across a module boundary. A milestone on one job could point at another
     * customer's termin, and nothing downstream re-checked it: the achievement
     * alert would name the wrong contract and send finance to invoice a job that
     * had not moved an inch.
     */
    public function test_a_milestone_cannot_point_at_another_contracts_termin(): void
    {
        $other = $this->approvedContract('Instalasi ELV & ICT 12 Kantor Cabang', 9_800_000_000);
        $foreign = ContractTermin::query()->create([
            'contract_id' => $other->id,
            'termin_no' => 2,
            'name' => 'Progress 40%',
            'percent' => 40,
            'amount' => 3_920_000_000,
        ]);

        $this->actingAs($this->adminUser())
            ->postJson('/api/projects/milestones', [
                'project_id' => $this->project->id,
                'name' => 'Progres fisik 50%',
                'due_date' => '2026-04-15',
                'termin_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('termin_id');

        $this->assertSame(0, Milestone::query()->count());
    }

    /** The same hole on update, which is where a termin is usually attached. */
    public function test_the_cross_contract_termin_is_refused_on_update_too(): void
    {
        $other = $this->approvedContract('Pemeliharaan CCTV & Akses Kontrol', 480_000_000);
        $foreign = ContractTermin::query()->create([
            'contract_id' => $other->id,
            'termin_no' => 2,
            'name' => 'Triwulan II 25%',
            'percent' => 25,
            'amount' => 120_000_000,
        ]);

        $milestone = $this->milestone();

        $this->actingAs($this->adminUser())
            ->putJson("/api/projects/milestones/{$milestone->id}", ['termin_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('termin_id');

        $this->assertNull($milestone->refresh()->termin_id);
    }

    /** The termin of the project's own contract is of course accepted. */
    public function test_a_termin_of_the_projects_own_contract_is_accepted(): void
    {
        $termin = $this->termin();

        $this->actingAs($this->adminUser())
            ->postJson('/api/projects/milestones', [
                'project_id' => $this->project->id,
                'name' => 'Progres fisik 50% — syarat penagihan Termin 2',
                'due_date' => '2026-04-15',
                'termin_id' => $termin->id,
            ])
            ->assertStatus(201);

        $this->assertSame($termin->id, Milestone::query()->value('termin_id'));
    }
}
