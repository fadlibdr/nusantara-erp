<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Notification;
use Modules\Core\Support\ApprovalQueue;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseRequisition;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * erp:approval-watch + ApprovalQueue — the date no table has a column for.
 *
 * Reproduces what production showed on 4 Sep 2026: a document sitting in
 * `submitted` for 33 days with nobody reminded, because the first submission
 * notice had long been read and the dashboard card did not carry its type.
 * Pins four things: below the threshold nothing fires; at the threshold every
 * approve-permission holder except the submitter is reminded; at twice the
 * threshold fin.approve (direktur) is pulled in; and the queue itself never
 * offers a document to the person who requested it, even when the seed wrote
 * no `submitted` row (since T3.4 the maker-checker guard refuses that case
 * through the same owner column — the inbox must not offer it either).
 */
class ApprovalWatchTest extends ErpTestCase
{
    private const TODAY = '2026-09-04';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

    // -------------------------------------------------------------- fixtures

    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);
        $user = User::query()->create([
            'name' => 'Pengguna '.substr(md5(implode('|', $permissions)), 0, 4),
            'email' => substr(md5(implode('|', $permissions).microtime()), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function submittedPr(User $submitter, int $daysAgo): PurchaseRequisition
    {
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => 'Uji umur antrean',
            'requested_by' => $submitter->id,
        ]);
        Carbon::setTestNow(Carbon::parse(self::TODAY)->subDays($daysAgo)->setTime(8, 0));
        $pr->submit($submitter);
        Carbon::setTestNow(self::TODAY.' 09:00:00');

        return $pr->fresh();
    }

    /** @return Collection<int, Notification> */
    private function alarms(): Collection
    {
        return Notification::query()->where('event', Notification::SYSTEM)->get();
    }

    // ----------------------------------------------------------------- tests

    public function test_nothing_fires_below_the_threshold(): void
    {
        $maker = $this->userWith('prc.create');
        $this->userWith('prc.approve');
        $this->submittedPr($maker, 2);

        $this->artisan('erp:approval-watch')->assertExitCode(0);

        $this->assertCount(0, $this->alarms());
    }

    public function test_at_the_threshold_every_approver_but_the_submitter_is_reminded(): void
    {
        $maker = $this->userWith('prc.create', 'prc.approve'); // holds approve too — must still not be nagged for own doc
        $checker = $this->userWith('prc.approve');
        $bystander = $this->userWith('fin.approve');
        $pr = $this->submittedPr($maker, 6);

        $this->artisan('erp:approval-watch')->assertExitCode(0);

        $alarms = $this->alarms();
        $this->assertTrue($alarms->contains('user_id', $checker->id), 'the checker is reminded');
        $this->assertFalse($alarms->contains('user_id', $bystander->id), 'fin.approve is NOT pulled in before escalation');
        $this->assertStringContainsString($pr->code, $alarms->firstWhere('user_id', $checker->id)->title);
        $this->assertStringContainsString('6 hari', $alarms->firstWhere('user_id', $checker->id)->title);

        // Running again the same morning is idempotent: the unread reminder suppresses a duplicate.
        $this->artisan('erp:approval-watch')->assertExitCode(0);
        $this->assertCount($alarms->count(), $this->alarms());
    }

    public function test_at_twice_the_threshold_the_director_is_escalated_to(): void
    {
        $maker = $this->userWith('prc.create');
        $checker = $this->userWith('prc.approve');
        $director = $this->userWith('fin.approve');
        $pr = $this->submittedPr($maker, 33); // the production case

        $this->artisan('erp:approval-watch')->assertExitCode(0);

        $alarms = $this->alarms();
        $this->assertTrue($alarms->contains('user_id', $director->id), 'direktur (fin.approve) is escalated to');
        $this->assertTrue($alarms->contains('user_id', $checker->id));
        $this->assertStringStartsWith('Eskalasi:', $alarms->firstWhere('user_id', $director->id)->title);
        $this->assertStringContainsString('33 hari', $alarms->firstWhere('user_id', $director->id)->title);
        $this->assertSame("#/d/procurement/purchase-requisitions/{$pr->id}", $alarms->first()->link);
    }

    public function test_the_threshold_is_a_setting(): void
    {
        $maker = $this->userWith('prc.create');
        $this->userWith('prc.approve');
        $this->submittedPr($maker, 6);
        $this->setSetting('approvals.aging_days', 10);

        $this->artisan('erp:approval-watch')->assertExitCode(0);

        $this->assertCount(0, $this->alarms());
    }

    public function test_the_queue_never_offers_a_document_to_its_own_requester_even_without_a_submit_row(): void
    {
        $requester = $this->userWith('prc.create', 'prc.approve');
        $other = $this->userWith('prc.approve');

        // Seeded straight to submitted: no core_approvals row, exactly like
        // PR/2026/III/0002 on production — which admin then approved in one click.
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'submitted',
            'purpose' => 'Seed langsung submitted',
            'requested_by' => $requester->id,
        ]);

        $forRequester = collect(ApprovalQueue::pending($requester)['rows'])->pluck('code');
        $forOther = collect(ApprovalQueue::pending($other)['rows'])->pluck('code');

        $this->assertFalse($forRequester->contains($pr->code), 'own document is not offered');
        $this->assertTrue($forOther->contains($pr->code), 'a second approver still sees it');
    }
}
