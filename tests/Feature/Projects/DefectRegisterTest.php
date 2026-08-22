<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use LogicException;
use Modules\Core\Models\Notification;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectSource;
use Modules\Projects\Enums\DefectStatus;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\DefectService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Feature\HrPayroll\PayrollFixtures;

/**
 * Register defect (punch list / daftar temuan).
 *
 * PRJ-2026-001 holds Rp 2.425.000.000 of retensi — 5% of Rp 48.500.000.000,
 * crm_contract_termins id 5, still unbilled — as security against exactly one
 * thing: defects. And there was nowhere in the system to record a defect. The
 * only place one ever appeared was prose, including the BAST rejection note
 * "Masih ada defect list terbuka." — a sentence naming a list that does not
 * exist, with no severity, no owner, no due date and no way to ask what is still
 * open.
 */
class DefectRegisterTest extends ErpTestCase
{
    use PayrollFixtures;

    private DefectService $service;

    private Project $project;

    private ?User $admin = null;

    private ?User $siteManager = null;

    /** Memoised: ErpTestCase::adminUser() mints admin@test.local, which is unique. */
    private function admin(): User
    {
        return $this->admin ??= $this->adminUser();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->service = app(DefectService::class);
        $this->project = $this->makeProject();
    }

    // -------------------------------------------------------------- fixtures

    private function makeProject(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-'.str_pad((string) (Project::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'contract_value' => 48_500_000_000,
            'retention_pct' => 5,
            'warranty_months' => 12,
            'status' => ProjectStatus::Active,
        ], $attributes));
    }

    private function defect(array $attributes = []): Defect
    {
        return $this->service->create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Retak rambut pada dinding koridor lantai 5',
            'location' => 'Lantai 5, zona B',
            'severity' => DefectSeverity::Minor,
            'source' => DefectSource::Internal,
            'reported_on' => '2026-07-01',
        ], $attributes));
    }

    /** Somebody who can run a site but not accept work on the customer's behalf. */
    private function siteManager(): User
    {
        if ($this->siteManager !== null) {
            return $this->siteManager;
        }

        $role = Role::findOrCreate('site-manager', 'web');
        $role->syncPermissions(['prj.view', 'prj.create', 'prj.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Slamet Riyadi',
            'email' => 'sm@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $this->siteManager = $user;
    }

    /** The PM: holds prj.update, which is who a new defect has to reach. */
    private function projectManager(): User
    {
        $role = Role::findOrCreate('project-manager', 'web');
        $role->syncPermissions(['prj.view', 'prj.create', 'prj.update', 'prj.approve']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Rina Wijaya',
            'email' => 'pm@test.local',
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

    // -------------------------------------------------------------- recording

    public function test_a_defect_gets_a_number_and_opens(): void
    {
        $defect = $this->defect();

        // No config('erp.documents') entry — DocumentNumberService's documented
        // fallback, strtoupper($type).'/{Y}/{RM}/{N4}', gives DEF/2026/VIII/0001.
        $this->assertMatchesRegularExpression('#^DEF/2026/[IVX]+/\d{4}$#', $defect->code);
        $this->assertSame(DefectStatus::Open, $defect->status);
        $this->assertSame('2026-07-01', $defect->reported_on->toDateString());
    }

    /**
     * THE DECISION THAT LOOKS WRONG AND IS NOT. Masa pemeliharaan runs AFTER
     * BAST I, and since approving BAST II closes the project today, a warranty
     * claim arriving the week after has to land somewhere. Gating creation on
     * ProjectStatus::isOperational() would mean the customer's complaint about
     * a leaking panel is refused by the very system holding their retensi.
     */
    public function test_a_defect_may_be_raised_during_the_maintenance_period_on_a_closed_project(): void
    {
        $closed = $this->makeProject(['code' => 'PRJ-2026-009', 'status' => ProjectStatus::Closed]);

        $this->actingAs($this->admin())
            ->postJson('/api/projects/defects', [
                'project_id' => $closed->id,
                'title' => 'Kebocoran pada panel ELV ruang server lantai 2',
                'severity' => 'major',
                'source' => 'warranty',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'open');

        // Raising one does NOT reopen the project — that belongs to the
        // project-lifecycle package, not to the punch list.
        $this->assertSame(ProjectStatus::Closed, $closed->refresh()->status);
    }

    public function test_a_defect_hangs_off_a_wbs_task_a_location_or_neither(): void
    {
        $task = $this->project->wbsTasks()->create([
            'wbs_code' => 'B.3',
            'name' => 'Pembesian besi beton ulir',
            'weight_pct' => 10,
            'progress_pct' => 60,
            'sort_order' => 1,
        ]);

        $onTask = $this->defect(['wbs_task_id' => $task->id, 'location' => null]);
        $onPlace = $this->defect(['wbs_task_id' => null, 'location' => 'Lantai 5, zona B']);
        $onNeither = $this->defect(['wbs_task_id' => null, 'location' => null]);

        $this->assertSame($task->id, $onTask->wbs_task_id);
        $this->assertSame('Lantai 5, zona B', $onPlace->location);
        $this->assertNull($onNeither->wbs_task_id);
        $this->assertNull($onNeither->location);
        $this->assertSame(3, $task->project->defects()->count());
    }

    /**
     * The same unchecked-foreign-key hole MilestoneStoreRequest closed for
     * termins: a punch item on one job naming a work package on another makes
     * every count that reads (project_id, wbs_task_id) answer about the wrong
     * site — including the count that refuses BAST II.
     */
    public function test_a_defect_cannot_point_at_another_projects_wbs_task(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-2026-002', 'name' => 'ELV & Data Center Bank Artha Nusantara']);
        $foreign = $other->wbsTasks()->create([
            'wbs_code' => 'C.1',
            'name' => 'Instalasi CCTV',
            'weight_pct' => 20,
            'progress_pct' => 4.06,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/projects/defects', [
                'project_id' => $this->project->id,
                'title' => 'Kabel UTP tidak terlabel',
                'severity' => 'minor',
                'source' => 'handover',
                'wbs_task_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wbs_task_id')
            ->assertJsonPath('errors.wbs_task_id.0', 'Item WBS yang dipilih bukan milik proyek ini.');

        $this->assertSame(0, Defect::query()->count());
    }

    // -------------------------------------------------------------- lifecycle

    public function test_marking_a_defect_fixed_puts_it_up_for_verification(): void
    {
        $defect = $this->service->markFixed($this->defect(), '2026-07-10');

        // NOT closed. Somebody still has to look at it, and BAST II is that look.
        $this->assertSame(DefectStatus::ReadyForReview, $defect->status);
        $this->assertSame('2026-07-10', $defect->fixed_at->toDateString());
        $this->assertTrue($defect->status->isOpen(), 'a repair nobody accepted is still on the punch list');
    }

    public function test_verifying_a_defect_closes_it_and_records_who_accepted_it_and_when(): void
    {
        $mk = $this->admin();
        $defect = $this->service->markFixed($this->defect(), '2026-07-10');

        $verified = $this->service->verify($defect, $mk, '2026-07-14');

        $this->assertSame(DefectStatus::Closed, $verified->status);
        $this->assertSame('2026-07-14', $verified->verified_at->toDateString());
        $this->assertSame($mk->id, $verified->verified_by);
        $this->assertSame('2026-07-10', $verified->fixed_at->toDateString(), 'the declared repair date survives');
    }

    /**
     * An MK who signs an item off on the spot during a punch walk is the normal
     * case on site. Refusing that would be a block the business cannot satisfy,
     * and a block people cannot satisfy is a block they route around.
     */
    public function test_verifying_a_defect_nobody_declared_fixed_stamps_the_repair_date_from_the_verification(): void
    {
        $verified = $this->service->verify($this->defect(), $this->admin(), '2026-07-14');

        $this->assertSame('2026-07-14', $verified->fixed_at->toDateString());
        $this->assertSame('2026-07-14', $verified->verified_at->toDateString());
    }

    /**
     * THE GUARD THAT MATTERS. waive() is the one documented way past the BAST II
     * hard block on critical/major items. An escape valve with no writing on it
     * is a delete button with extra steps.
     */
    public function test_a_defect_cannot_be_waived_without_a_reason(): void
    {
        $defect = $this->defect(['severity' => DefectSeverity::Major]);

        try {
            $this->service->waive($defect, 'ok');
            $this->fail('Expected LogicException when waiving without a reason.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('harus disertai alasan', $e->getMessage());
        }

        $this->assertSame(DefectStatus::Open, $defect->refresh()->status);
    }

    public function test_a_waived_defect_keeps_the_reason_and_the_person_who_accepted_it(): void
    {
        $customerRep = $this->admin();
        $reason = 'Pelanggan menerima sisa cat pada plafon koridor apa adanya; tidak dikerjakan ulang.';

        $waived = $this->service->waive($this->defect(), $reason, $customerRep, '2026-07-20');

        $this->assertSame(DefectStatus::Waived, $waived->status);
        $this->assertSame($reason, $waived->resolution_note);
        $this->assertSame($customerRep->id, $waived->verified_by);
        $this->assertSame('2026-07-20', $waived->verified_at->toDateString());
        $this->assertTrue($waived->status->isTerminal());
    }

    /** A terminal defect is a record of an acceptance, exactly like a closed K3 row. */
    public function test_a_closed_defect_cannot_be_edited(): void
    {
        $closed = $this->service->verify($this->defect(), $this->admin(), '2026-07-14');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak dapat diubah/');

        $this->service->update($closed, ['title' => 'Diubah diam-diam']);
    }

    /**
     * A repair that did not hold is the most useful thing a punch list can tell
     * you. A second row for the same item would double-count it in every count
     * the BAST II gate and the dashboard read.
     */
    public function test_a_reopened_defect_returns_to_repair_and_says_why_it_came_back(): void
    {
        $closed = $this->service->verify($this->defect(), $this->admin(), '2026-07-14');

        $reopened = $this->service->reopen($closed, 'Retak muncul kembali di titik yang sama setelah dua minggu.', '2026-08-01');

        $this->assertSame(DefectStatus::InProgress, $reopened->status);
        $this->assertNull($reopened->fixed_at);
        $this->assertNull($reopened->verified_at);
        $this->assertNull($reopened->verified_by);
        $this->assertStringContainsString('Dibuka kembali (01-08-2026): Retak muncul kembali', $reopened->resolution_note);
    }

    // --------------------------------------------------------------- deletion

    /**
     * Deleting is how the register would be emptied to clear the BAST II block,
     * so anything anybody has repaired or accepted is out of reach.
     */
    public function test_a_verified_defect_cannot_be_deleted(): void
    {
        $verified = $this->service->verify($this->defect(), $this->admin(), '2026-07-14');

        $this->actingAs($this->admin())
            ->deleteJson("/api/projects/defects/{$verified->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'tidak dapat dihapus'));

        $this->assertSame(1, Defect::query()->count());
    }

    public function test_an_untouched_open_defect_can_be_deleted(): void
    {
        $defect = $this->defect();

        $this->actingAs($this->admin())
            ->deleteJson("/api/projects/defects/{$defect->id}")
            ->assertOk();

        $this->assertSame(0, Defect::query()->count());
    }

    // ------------------------------------------------------------ permissions

    /**
     * Accepting a repair is the CUSTOMER's act and it is the row BAST II counts,
     * so it sits on prj.approve — which the seeded site-manager role deliberately
     * does not hold.
     */
    public function test_waiving_a_defect_needs_the_approve_permission(): void
    {
        $defect = $this->defect();

        $this->actingAs($this->siteManager())
            ->postJson("/api/projects/defects/{$defect->id}/waive", [
                'reason' => 'Pelanggan menerima apa adanya.',
            ])
            ->assertForbidden();

        $this->assertSame(DefectStatus::Open, $defect->refresh()->status);
    }

    /** Declaring the repair done is site work: cheap, reversible, prj.update. */
    public function test_marking_a_defect_fixed_only_needs_the_update_permission(): void
    {
        $defect = $this->defect();

        $this->actingAs($this->siteManager())
            ->postJson("/api/projects/defects/{$defect->id}/fixed", ['fixed_at' => '2026-07-10'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->actingAs($this->siteManager())
            ->postJson("/api/projects/defects/{$defect->id}/verify")
            ->assertForbidden();
    }

    // ------------------------------------------------- severity downgrades

    /**
     * Downgrading an open critical/major finding clears the BAST II hard block,
     * so it costs exactly what waive() costs: prj.approve plus a written
     * reason. Before this guard, one PUT by anybody holding prj.update erased
     * the block more cheaply than the register's own escape valve — and the
     * frozen prerequisite_snapshot then swore no critical item was ever open.
     */
    public function test_downgrading_an_open_critical_defect_with_only_the_update_permission_is_refused(): void
    {
        $blocker = $this->defect(['severity' => DefectSeverity::Critical, 'title' => 'Lift tidak level di lantai 3']);

        $this->actingAs($this->siteManager())
            ->putJson("/api/projects/defects/{$blocker->id}", ['severity' => 'minor'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'butuh wewenang persetujuan'));

        $this->assertSame(DefectSeverity::Critical, $blocker->refresh()->severity);
    }

    public function test_downgrading_a_blocking_defect_without_a_reason_is_refused_even_for_an_approver(): void
    {
        $blocker = $this->defect(['severity' => DefectSeverity::Major]);

        $this->actingAs($this->projectManager())
            ->putJson("/api/projects/defects/{$blocker->id}", ['severity' => 'minor'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'harus disertai alasan'));

        $this->assertSame(DefectSeverity::Major, $blocker->refresh()->severity);
    }

    public function test_an_approver_downgrades_with_a_reason_and_the_old_severity_stays_on_the_record(): void
    {
        $blocker = $this->defect(['severity' => DefectSeverity::Critical]);

        $this->actingAs($this->projectManager())
            ->putJson("/api/projects/defects/{$blocker->id}", [
                'severity' => 'minor',
                'downgrade_reason' => 'Salah klasifikasi saat punch walk: retak rambut non-struktural, bukan kritis.',
            ])
            ->assertOk()
            ->assertJsonPath('data.severity', 'minor');

        // The snapshot is never the only record: the note keeps what the item
        // used to be, who-knows-when readable years later.
        $note = (string) $blocker->refresh()->resolution_note;
        $this->assertStringContainsString('Keparahan diturunkan', $note);
        $this->assertStringContainsString('Kritis (menghentikan fungsi)', $note);
        $this->assertStringContainsString('Salah klasifikasi', $note);
    }

    /** Making a finding heavier never clears a gate, so it stays cheap. */
    public function test_upgrading_a_minor_defect_needs_no_special_authority_or_reason(): void
    {
        $minor = $this->defect(['severity' => DefectSeverity::Minor]);

        $this->actingAs($this->siteManager())
            ->putJson("/api/projects/defects/{$minor->id}", ['severity' => 'critical'])
            ->assertOk()
            ->assertJsonPath('data.severity', 'critical');
    }

    // ---------------------------------------------------------- the questions

    /** "Apa yang masih terbuka di proyek ini" — the first question it exists for. */
    public function test_the_register_answers_which_defects_are_still_open_for_a_project(): void
    {
        $this->defect(['title' => 'Terbuka']);
        $this->service->markFixed($this->defect(['title' => 'Menunggu verifikasi']));
        $this->service->verify($this->defect(['title' => 'Selesai']), $this->admin());
        $this->service->waive($this->defect(['title' => 'Dispensasi']), 'Pelanggan menerima apa adanya.', $this->admin());

        $this->actingAs($this->admin())
            ->getJson("/api/projects/defects?project_id={$this->project->id}&open=1")
            ->assertOk()
            // ready_for_review counts as open: nobody has accepted it yet.
            ->assertJsonCount(2, 'data');
    }

    public function test_the_register_answers_which_repairs_are_overdue(): void
    {
        $this->defect(['title' => 'Lewat target', 'due_date' => '2026-06-20']);
        $this->defect(['title' => 'Masih ada waktu', 'due_date' => now()->addMonth()->toDateString()]);
        // Past its date but already accepted — never overdue again.
        $this->service->verify($this->defect(['title' => 'Sudah diterima', 'due_date' => '2026-06-20']), $this->admin());

        $this->actingAs($this->admin())
            ->getJson('/api/projects/defects?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.title', 'Lewat target');
    }

    /**
     * ONE boundary rule for the figures and the filter: due TODAY is not yet
     * overdue — the site has until the end of the day. isPast() on a date cast
     * to 00:00:00 counted an item overdue from 00:00:01 on its own target date
     * while the list query still excluded it, so the stat card said "Lewat
     * target perbaikan: 1" above a table reading "Tidak ada baris".
     */
    public function test_a_defect_due_today_is_not_overdue_in_the_figures_or_the_list_until_tomorrow(): void
    {
        $dueToday = $this->defect(['title' => 'Jatuh tempo hari ini', 'due_date' => now()->toDateString()]);
        $this->defect(['title' => 'Lewat sejak kemarin', 'due_date' => now()->subDay()->toDateString()]);

        $this->assertFalse($dueToday->refresh()->isOverdue());

        $summary = $this->actingAs($this->admin())
            ->getJson("/api/projects/defects/summary?project_id={$this->project->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['overdue_count']);

        // The same single row the count promises, from the same rule.
        $this->actingAs($this->admin())
            ->getJson("/api/projects/defects?project_id={$this->project->id}&overdue=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Lewat sejak kemarin')
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_the_summary_counts_open_criticals_and_the_oldest_open_defect(): void
    {
        $oldest = $this->defect([
            'title' => 'Lift tidak level di lantai 3',
            'severity' => DefectSeverity::Critical,
            'reported_on' => '2026-06-01',
        ]);
        $this->defect(['severity' => DefectSeverity::Major, 'reported_on' => '2026-07-01']);
        $this->defect(['severity' => DefectSeverity::Minor, 'reported_on' => '2026-07-05', 'due_date' => '2026-07-10']);
        $this->service->waive(
            $this->defect(['severity' => DefectSeverity::Critical, 'reported_on' => '2026-05-01']),
            'Pelanggan menerima apa adanya, diselesaikan di luar kontrak.',
            $this->admin(),
        );

        $summary = $this->actingAs($this->admin())
            ->getJson("/api/projects/defects/summary?project_id={$this->project->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $summary['total']);
        $this->assertSame(3, $summary['open_count']);
        // The number that will refuse BAST II: critical + major, waived excluded.
        $this->assertSame(2, $summary['open_blocking_count']);
        $this->assertSame(1, $summary['overdue_count']);
        $this->assertSame($oldest->code, $summary['oldest_open_code']);
    }

    // -------------------------------------------------------- who gets told

    /**
     * The job is nominally finished and nobody is watching the punch list any
     * more — but this is money the contractor will spend, and until now nothing
     * told anybody it had arrived.
     */
    public function test_a_warranty_claim_on_a_closed_project_reaches_the_project_manager(): void
    {
        $pm = $this->projectManager();
        $closed = $this->makeProject(['code' => 'PRJ-2026-008', 'status' => ProjectStatus::Closed]);

        $defect = $this->service->create([
            'project_id' => $closed->id,
            'title' => 'Kebocoran pada panel ELV ruang server lantai 2',
            'severity' => DefectSeverity::Major,
            'source' => DefectSource::Warranty,
            'due_date' => '2026-08-15',
        ]);

        $inbox = $this->inboxOf($pm);

        $this->assertCount(1, $inbox);
        $this->assertStringContainsString($defect->code, $inbox->first()->title);
        $this->assertStringContainsString('PRJ-2026-008', $inbox->first()->title);
        $this->assertStringContainsString('Masa pemeliharaan', $inbox->first()->body);
    }

    /**
     * And the quiet it keeps. A bell that rings for every snagging item on a live
     * site is a bell that gets muted, and the muting takes the warranty claims
     * with it.
     */
    public function test_an_internal_minor_finding_on_a_running_project_rings_nobody(): void
    {
        $pm = $this->projectManager();

        $this->defect(['severity' => DefectSeverity::Minor, 'source' => DefectSource::Internal]);

        $this->assertCount(0, $this->inboxOf($pm));

        // A critical one on the same live project does ring: it stops BAST II
        // by itself.
        $this->defect(['severity' => DefectSeverity::Critical, 'title' => 'Lift tidak level di lantai 3']);

        $this->assertCount(1, $this->inboxOf($pm));
    }
}
