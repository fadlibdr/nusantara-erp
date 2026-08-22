<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Finance\Models\PeriodEvent;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The HTTP contract the #periods screen consumes, and the permission split
 * underneath it.
 *
 * Closing is fin.post — the person who posts the last journal of the month is
 * the person who closes it. Reopening is fin.approve, because it alters figures
 * that have already been reported and must be a strictly higher bar than the
 * act it undoes: whoever can post must not be able to unlock the period they
 * want to post into.
 */
class PeriodCloseApiTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->seedLedger(2026);
        $this->closeEverythingBefore(2026, 6);
    }

    public function test_listing_fiscal_periods_returns_the_year_with_its_close_state(): void
    {
        $this->periods()->close($this->period(2026, 6), $this->closerUser());

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('/api/finance/fiscal-periods?year=2026')
            ->assertOk();

        $this->assertSame(2026, $response->json('data.year'));
        $this->assertCount(12, $response->json('data.periods'));
        $this->assertSame([2026], $response->json('data.years'));

        $june = collect($response->json('data.periods'))->firstWhere('month', 6);
        $this->assertSame('Juni 2026', $june['label']);
        $this->assertSame('closed', $june['status']);
        $this->assertSame('Ditutup', $june['status_label']);
        $this->assertSame('Sri Wahyuni', $june['closed_by']['name']);
        $this->assertNotNull($june['closed_at']);

        $august = collect($response->json('data.periods'))->firstWhere('month', 8);
        $this->assertTrue($august['is_current']);
        $this->assertFalse($august['has_ended']);
    }

    public function test_the_checklist_endpoint_returns_every_item_with_its_severity_and_status(): void
    {
        $this->makeBankAccount();
        $period = $this->period(2026, 6);

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson("/api/finance/fiscal-periods/{$period->id}/checklist")
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(11, $items);
        $this->assertSame([
            'period_ended', 'earlier_periods_closed', 'payroll_present', 'depreciation_present',
            'plant_accrued', 'dangling_documents', 'revenue_recognition_posted', 'trial_balance_balanced',
            'subledger_tied', 'bank_reconciled', 'tax_export_ready',
        ], array_column($items, 'key'));

        foreach ($items as $item) {
            $this->assertContains($item['severity'], ['block', 'warn']);
            $this->assertContains($item['status'], ['ok', 'fail', 'na']);
            $this->assertNotSame('', $item['detail']);
        }

        $this->assertSame(0, $response->json('data.summary.blockers'));
        $this->assertSame(1, $response->json('data.summary.warnings'));
        $this->assertTrue($response->json('data.summary.can_close'));
        $this->assertSame([], $response->json('data.events'));
    }

    public function test_closing_requires_fin_post(): void
    {
        $period = $this->period(2026, 6);

        $this->actingAs($this->userWith(['fin.view', 'fin.approve']), 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/close")
            ->assertForbidden();

        $this->assertTrue($period->fresh()->isOpen());

        $this->actingAs($this->userWith(['fin.view', 'fin.post']), 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/close")
            ->assertOk()
            ->assertJsonPath('message', 'Periode Juni 2026 ditutup.')
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_reopening_requires_fin_approve_and_is_refused_for_a_user_holding_only_fin_post(): void
    {
        $poster = $this->userWith(['fin.view', 'fin.post']);
        $period = $this->period(2026, 6);

        $this->actingAs($poster, 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/close")
            ->assertOk();

        // The whole point of the split: whoever closed it cannot undo it.
        $this->actingAs($poster, 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/reopen", [
                'note' => 'Mau posting satu jurnal lagi ke Juni.',
            ])
            ->assertForbidden();

        $this->assertTrue($period->fresh()->isClosed());

        $this->actingAs($this->userWith(['fin.view', 'fin.approve']), 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/reopen", [
                'note' => 'Tagihan vendor Juni terlambat masuk; disetujui direktur.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $this->assertSame('reopened', PeriodEvent::query()->latest('id')->first()->action->value);
    }

    public function test_reopening_without_a_note_answers_422_in_indonesian(): void
    {
        $period = $this->period(2026, 6);
        $this->periods()->close($period, $this->closerUser());

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/reopen", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0', 'Alasan membuka periode wajib diisi — ini tercatat permanen.');
    }

    public function test_generating_a_calendar_requires_fin_create(): void
    {
        $this->actingAs($this->userWith(['fin.view', 'fin.post']), 'sanctum')
            ->postJson('/api/finance/fiscal-periods/generate', ['year' => 2027])
            ->assertForbidden();

        $this->actingAs($this->userWith(['fin.view', 'fin.create']), 'sanctum')
            ->postJson('/api/finance/fiscal-periods/generate', ['year' => 2027])
            ->assertCreated()
            ->assertJsonPath('data.created', 12)
            ->assertJsonPath('data.created_status', 'open');

        $this->actingAs($this->userWith(['fin.view', 'fin.create']), 'sanctum')
            ->postJson('/api/finance/fiscal-periods/generate', ['year' => 2072])
            ->assertStatus(422);
    }

    public function test_a_refused_close_answers_422_with_the_blocker_message(): void
    {
        $this->draftJournal([['1-1100', 5000000, 0], ['4-1100', 0, 5000000]], '2026-06-30');
        $period = $this->period(2026, 6);

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/close")
            ->assertStatus(422);

        $this->assertStringContainsString('belum dapat ditutup', $response->json('message'));
        $this->assertStringContainsString('1 dokumen menggantung', $response->json('message'));
        $this->assertTrue($period->fresh()->isOpen());
    }

    public function test_the_close_endpoint_returns_the_recomputed_checklist_in_its_payload(): void
    {
        $this->makeBankAccount();
        $period = $this->period(2026, 6);

        $response = $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/finance/fiscal-periods/{$period->id}/close", [
                'note' => 'Rekening koran BCA Juni belum diterima dari bank.',
                'acknowledge' => ['bank_reconciled'],
            ])
            ->assertOk();

        $this->assertCount(11, $response->json('data.items'));
        $this->assertSame('closed', $response->json('data.status'));
        $this->assertFalse($response->json('data.summary.can_close'));
        $this->assertSame('Periode Juni 2026 sudah ditutup.', $response->json('data.summary.close_blocked_reason'));

        // The event history comes back with it, overrides and all.
        $events = $response->json('data.events');
        $this->assertCount(1, $events);
        $this->assertSame('Ditutup', $events[0]['action_label']);
        $this->assertSame(['bank_reconciled'], $events[0]['overrides']);
        $this->assertStringContainsString('BCA Juni', $events[0]['note']);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
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
