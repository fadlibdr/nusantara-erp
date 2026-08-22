<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Core\Models\Notification;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectSource;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Exceptions\BastPrerequisiteException;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\BastPrerequisiteService;
use Modules\Projects\Services\DefectService;
use Modules\Projects\Services\ProjectService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Prasyarat BAST II — the gate in front of Rp 2.425.000.000.
 *
 * Approving BAST II closes the project AND publishes the date on which the
 * customer's retensi becomes collectible (ArRetentionService reads
 * prj_bast.retention_release_due). Before this, the only thing between a
 * submitted document and that outcome was one click by anybody holding
 * prj.approve: no BAST I had to exist, the date could precede the first
 * handover, a second BAST II could close an already closed project, and the
 * punch list was not consulted — because there was no punch list.
 *
 * The live file makes the stakes concrete. `select count(*) from prj_bast`
 * returns 0, so nothing was ever handed over under the old rules and there is
 * nothing to backfill; CTR/2026/I/0001 carries Rp 2.425.000.000 of retensi on
 * crm_contract_termins id 5, unbilled; and PRJ-2026-001 reports
 * actual_progress_pct = 55,0000 four months from its BAST-15% billing, which is
 * precisely why physical progress WARNS here and never blocks.
 */
class BastTwoPrerequisiteTest extends ErpTestCase
{
    private ProjectService $projects;

    private DefectService $defects;

    private BastPrerequisiteService $checklist;

    private Project $project;

    private ?User $admin = null;

    private ?User $approver = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->projects = app(ProjectService::class);
        $this->defects = app(DefectService::class);
        $this->checklist = app(BastPrerequisiteService::class);
        $this->project = $this->makeProject();
    }

    // -------------------------------------------------------------- fixtures

    /**
     * PRJ-2026-001 as it would look on the day BAST II is genuinely due: the
     * work finished, the warranty served. Every deviation from that is set
     * explicitly by the test that cares about it.
     */
    private function makeProject(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'contract_value' => 48_500_000_000,
            'retention_pct' => 5,
            'warranty_months' => 12,
            'actual_progress_pct' => 100,
            'status' => ProjectStatus::Warranty,
        ], $attributes));
    }

    private function admin(): User
    {
        return $this->admin ??= $this->adminUser();
    }

    /** A second pair of eyes — maker-checker means the approver is never the submitter. */
    private function approver(): User
    {
        if ($this->approver !== null) {
            return $this->approver;
        }

        $role = Role::findOrCreate('direktur', 'web');
        $role->syncPermissions(['prj.view', 'prj.update', 'prj.approve']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Ir. Bambang Sutrisno',
            'email' => 'direktur@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $this->approver = $user;
    }

    private function bast(string $type, array $data = []): Bast
    {
        return $this->projects->createBast(array_merge([
            'project_id' => $this->project->id,
            'bast_type' => $type,
            'handover_date' => '2026-12-20',
            'customer_representative' => 'Ir. Bambang (Owner Rep.)',
        ], $data));
    }

    /** Serah terima pertama 20-12-2026; masa pemeliharaan 12 bulan → 20-12-2027. */
    private function approvedFirstHandover(array $data = []): Bast
    {
        $first = $this->bast('bast1', $data);
        $first->submit();
        $this->projects->approveBast($first, $this->approver());

        return $first->refresh();
    }

    private function submittedSecond(array $data = []): Bast
    {
        $second = $this->bast('bast2', array_merge(['handover_date' => '2027-12-20'], $data));
        $second->submit();

        return $second;
    }

    private function defect(array $attributes = []): Defect
    {
        return $this->defects->create(array_merge([
            'project_id' => $this->project->id,
            'title' => 'Lift barang tidak level di lantai 3',
            'location' => 'Core lift, lantai 3',
            'severity' => DefectSeverity::Critical,
            'source' => DefectSource::Handover,
            'reported_on' => '2027-06-01',
        ], $attributes));
    }

    /** @return array<string, array<string, mixed>> keyed by check key */
    private function checks(Bast $bast): array
    {
        return collect($this->checklist->evaluate($bast)['checks'])->keyBy('key')->all();
    }

    // -------------------------------------------------- BAST I has to exist

    /**
     * BAST II is by definition the END of the masa pemeliharaan BAST I started.
     * Without an approved BAST I there is no period, no retention_release_due
     * and nothing being handed back.
     */
    public function test_bast_two_is_refused_when_no_bast_one_was_ever_approved(): void
    {
        $second = $this->submittedSecond();

        try {
            $this->projects->approveBast($second, $this->approver());
            $this->fail('Expected BastPrerequisiteException when no BAST I is on record.');
        } catch (BastPrerequisiteException $e) {
            $this->assertStringContainsString('belum ada BAST I yang disetujui', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $second->refresh()->status);
        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    public function test_a_draft_bast_one_is_not_a_handover(): void
    {
        $this->bast('bast1'); // left in draft — nobody has signed anything
        $second = $this->submittedSecond();

        $this->expectException(BastPrerequisiteException::class);
        $this->expectExceptionMessageMatches('/belum ada BAST I yang disetujui/');

        $this->projects->approveBast($second, $this->approver());
    }

    public function test_bast_two_is_approved_once_an_approved_bast_one_is_on_record(): void
    {
        $this->approvedFirstHandover();
        $second = $this->submittedSecond();

        $this->projects->approveBast($second, $this->admin());

        $this->assertSame(DocumentStatus::Approved, $second->refresh()->status);
        $this->assertSame(ProjectStatus::Closed, $this->project->refresh()->status);
    }

    // ------------------------------------------------------ the punch list

    public function test_bast_two_is_refused_while_a_critical_defect_is_open(): void
    {
        $this->approvedFirstHandover();
        $blocker = $this->defect();
        $second = $this->submittedSecond();

        try {
            $this->projects->approveBast($second, $this->approver());
            $this->fail('Expected BastPrerequisiteException while a critical defect is open.');
        } catch (BastPrerequisiteException $e) {
            $this->assertStringContainsString('temuan kritis/mayor masih terbuka', $e->getMessage());
            $this->assertStringContainsString($blocker->code, $e->getMessage(), 'the refusal names its blocker');
        }

        $this->assertSame(DocumentStatus::Submitted, $second->refresh()->status);
    }

    /**
     * THE DECISION THAT COSTS ONE CLICK AND CLOSES THE WHOLE HOLE. BAST II *is*
     * the customer's acceptance, so an item that merely claims to be repaired
     * has been accepted by nobody. Letting ready_for_review through would
     * reproduce exactly what was being fixed.
     */
    public function test_bast_two_is_refused_while_a_major_defect_is_only_waiting_for_verification(): void
    {
        $this->approvedFirstHandover();
        $this->defects->markFixed($this->defect(['severity' => DefectSeverity::Major]), '2027-11-01');
        $second = $this->submittedSecond();

        $this->expectException(BastPrerequisiteException::class);
        $this->expectExceptionMessageMatches('/temuan kritis\/mayor masih terbuka/');

        $this->projects->approveBast($second, $this->approver());
    }

    public function test_bast_two_goes_through_once_the_critical_defect_is_verified_closed(): void
    {
        $this->approvedFirstHandover();
        $blocker = $this->defect();
        $second = $this->submittedSecond();

        $this->defects->verify($blocker, $this->approver(), '2027-12-15');
        $this->projects->approveBast($second, $this->admin());

        $this->assertSame(DocumentStatus::Approved, $second->refresh()->status);
    }

    /**
     * The escape valve, and the reason the hard block is satisfiable at all: the
     * customer looked at the item and accepted it as is. The record of that
     * acceptance sits on the item — with who accepted it and why — not buried in
     * one sentence attached to a Rp 2,4 miliar approval.
     */
    public function test_a_waived_defect_no_longer_blocks_the_handover(): void
    {
        $this->approvedFirstHandover();
        $blocker = $this->defect();
        $second = $this->submittedSecond();

        $this->defects->waive(
            $blocker,
            'Pelanggan menerima ketidakrataan lift barang apa adanya; diselesaikan langsung dengan pabrikan lift.',
            $this->approver(),
            '2027-12-10',
        );

        $this->projects->approveBast($second, $this->admin());

        $this->assertSame(DocumentStatus::Approved, $second->refresh()->status);
    }

    // ---------------------------------------------------------- data sanity

    public function test_a_second_bast_two_cannot_close_an_already_closed_project(): void
    {
        $this->approvedFirstHandover();
        $first = $this->submittedSecond();
        $this->projects->approveBast($first, $this->admin());

        $duplicate = $this->submittedSecond(['handover_date' => '2027-12-28']);

        try {
            $this->projects->approveBast($duplicate, $this->approver());
            $this->fail('Expected BastPrerequisiteException on a second BAST II.');
        } catch (BastPrerequisiteException $e) {
            $this->assertStringContainsString($first->code, $e->getMessage());
            $this->assertStringContainsString('sudah disetujui dan menutup proyek ini', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $duplicate->refresh()->status);
    }

    public function test_a_bast_two_dated_before_the_first_handover_is_refused(): void
    {
        $this->approvedFirstHandover(); // serah terima 20-12-2026
        $second = $this->submittedSecond(['handover_date' => '2026-11-30']);

        $this->expectException(BastPrerequisiteException::class);
        $this->expectExceptionMessageMatches('/mendahului serah terima pertama 20-12-2026/');

        $this->projects->approveBast($second, $this->approver());
    }

    // ------------------------------------------------------------- warnings

    /**
     * Sisa cat, sealant, list plafon: these linger and customers sign BAST II
     * with a snagging note every day in this industry. Hard-blocking here would
     * train people to delete rows, which is worse than having no register.
     */
    public function test_open_minor_defects_are_refused_without_a_reason(): void
    {
        $this->approvedFirstHandover();
        $this->defect(['severity' => DefectSeverity::Minor, 'title' => 'Sisa cat pada list plafon koridor']);
        $second = $this->submittedSecond();

        try {
            $this->projects->approveBast($second, $this->approver());
            $this->fail('Expected BastPrerequisiteException for an open minor defect with no reason.');
        } catch (BastPrerequisiteException $e) {
            $this->assertStringContainsString('1 temuan minor masih terbuka', $e->getMessage());
            $this->assertStringContainsString('sertakan alasan (minimal 20 karakter)', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $second->refresh()->status);
    }

    public function test_open_minor_defects_are_approved_with_a_recorded_reason(): void
    {
        $this->approvedFirstHandover();
        $this->defect(['severity' => DefectSeverity::Minor, 'title' => 'Sisa cat pada list plafon koridor']);
        $second = $this->submittedSecond();

        $this->projects->approveBast(
            $second,
            $this->admin(),
            null,
            'Sisa cat diselesaikan dalam masa garansi cat 2 tahun, disepakati pada rapat serah terima.',
        );

        $this->assertSame(DocumentStatus::Approved, $second->refresh()->status);
    }

    /**
     * warranty_months is master data that is wrong often enough — the demo's
     * maintenance contract CTR/2026/III/0003 carries 0 — and addendums do
     * shorten warranties. A hard block on a date derived from a field nobody can
     * correct after the fact is a block that gets routed around.
     */
    public function test_an_early_bast_two_warns_that_the_maintenance_period_has_not_ended(): void
    {
        $this->approvedFirstHandover(); // masa pemeliharaan berakhir 20-12-2027
        $second = $this->submittedSecond(['handover_date' => '2027-06-30']);

        $checks = $this->checks($second);
        $evaluation = $this->checklist->evaluate($second);

        $this->assertSame('warning', $checks['masa_pemeliharaan']['level']);
        $this->assertFalse($checks['masa_pemeliharaan']['passed']);
        $this->assertStringContainsString('baru berakhir 20-12-2027', $checks['masa_pemeliharaan']['detail']);
        $this->assertTrue($evaluation['can_approve'], 'a warning is not a block');
        $this->assertTrue($evaluation['needs_override']);
    }

    /**
     * The demo settles this one. PRJ-2026-001 reports actual_progress_pct
     * 55,0000 while four months from BAST-15% billing, and PRJ-2026-002 reports
     * 0,0000 on a live project with nine leaf tasks. Blocking on a WBS number
     * that stale would make BAST II unobtainable and teach people to fake
     * progress instead.
     */
    public function test_physical_progress_below_one_hundred_percent_warns_rather_than_blocks(): void
    {
        $this->project->forceFill(['actual_progress_pct' => 55])->save();
        $this->approvedFirstHandover();
        $second = $this->submittedSecond();

        $checks = $this->checks($second);

        $this->assertSame('warning', $checks['progres_fisik']['level']);
        $this->assertFalse($checks['progres_fisik']['passed']);
        $this->assertStringContainsString('55,00%', $checks['progres_fisik']['detail']);
        $this->assertTrue($this->checklist->evaluate($second)['can_approve']);

        // …and it does go through once somebody writes down why.
        $this->projects->approveBast(
            $second,
            $this->admin(),
            null,
            'Progres WBS tidak dimutakhirkan sejak Maret; progres fisik lapangan 100% dan dituangkan dalam BA opname.',
        );

        $this->assertSame(DocumentStatus::Approved, $second->refresh()->status);
    }

    // ------------------------------------------------------- the override

    /**
     * A gate whose blocks can be talked past with one free-text field is a
     * warning system wearing a gate's clothes.
     */
    public function test_an_override_reason_cannot_lift_a_hard_block(): void
    {
        $this->approvedFirstHandover();
        $this->defect(); // critical, open
        $second = $this->submittedSecond();

        try {
            $this->projects->approveBast(
                $second,
                $this->approver(),
                null,
                'Pelanggan sudah setuju secara lisan pada rapat serah terima tanggal 15 Desember 2027.',
            );
            $this->fail('Expected the hard block to survive an override reason.');
        } catch (BastPrerequisiteException $e) {
            $this->assertStringContainsString('temuan kritis/mayor masih terbuka', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $second->refresh()->status);
        $this->assertNull($second->refresh()->prerequisite_override_reason);
    }

    public function test_a_reason_shorter_than_a_sentence_is_refused(): void
    {
        $this->approvedFirstHandover();
        $this->defect(['severity' => DefectSeverity::Minor]);
        $second = $this->submittedSecond();

        $this->actingAs($this->admin())
            ->postJson("/api/projects/bast/{$second->id}/approve", ['override_reason' => 'sudah oke'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('override_reason')
            ->assertJsonPath(
                'errors.override_reason.0',
                'Alasan melewati prasyarat harus dijelaskan, minimal 20 karakter.',
            );

        $this->assertSame(DocumentStatus::Submitted, $second->refresh()->status);
    }

    public function test_the_override_reason_is_recorded_on_the_bast_and_in_the_approval_trail(): void
    {
        $this->approvedFirstHandover();
        $this->defect(['severity' => DefectSeverity::Minor]);
        $second = $this->submittedSecond();
        $reason = 'Sisa cat diselesaikan dalam masa garansi cat, disepakati pada rapat serah terima 20-12-2027.';

        $this->projects->approveBast($second, $this->admin(), 'Serah terima kedua.', $reason);

        $stored = $second->refresh();

        $this->assertSame($reason, $stored->prerequisite_override_reason);
        $this->assertSame($this->admin()->id, $stored->prerequisite_override_by);
        $this->assertNotNull($stored->prerequisite_override_at);

        // …and in core_approvals, which is the timeline an auditor actually reads.
        $note = (string) DB::table('core_approvals')
            ->where('approvable_type', Bast::class)
            ->where('approvable_id', $stored->id)
            ->where('action', 'approved')
            ->value('note');

        $this->assertStringContainsString('Serah terima kedua.', $note);
        $this->assertStringContainsString("Prasyarat dilewati: {$reason}", $note);
    }

    /**
     * The question an auditor asks a year later is not "was there an override" —
     * it is "what was true when Rp 2,4 miliar was released". Only a snapshot
     * answers that, so it is written on EVERY BAST II approval, clean or not.
     */
    public function test_every_bast_two_approval_stores_what_the_checklist_said(): void
    {
        $this->approvedFirstHandover();
        $second = $this->submittedSecond();

        $this->projects->approveBast($second, $this->admin());

        $snapshot = $second->refresh()->prerequisite_snapshot;

        $this->assertIsArray($snapshot);
        $this->assertTrue($snapshot['can_approve']);
        $this->assertFalse($snapshot['needs_override']);
        $this->assertNull($second->prerequisite_override_reason, 'a clean approval records no override');
        // 5% × Rp 48.500.000.000 — the sum this approval makes collectible.
        $this->assertEquals(2_425_000_000, $snapshot['retention_at_stake']);
        $this->assertEqualsCanonicalizing(
            [
                'bast_pertama', 'bast_kedua_tunggal', 'urutan_tanggal', 'masa_pemeliharaan',
                'defect_berat', 'defect_minor', 'defect_tercatat', 'progres_fisik',
                'retensi', 'termin_belum_ditagih',
            ],
            array_column($snapshot['checks'], 'key'),
        );
    }

    // ------------------------------------------------- what the approver sees

    /** 5% × Rp 48.500.000.000 — the number that belongs in front of the click. */
    public function test_the_prerequisite_endpoint_shows_the_retention_at_stake_before_anybody_clicks(): void
    {
        $this->approvedFirstHandover();
        $second = $this->submittedSecond();

        $this->actingAs($this->admin())
            ->getJson("/api/projects/bast/{$second->id}/prerequisites")
            ->assertOk()
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.retention_at_stake', fn ($amount): bool => (float) $amount === 2_425_000_000.0)
            ->assertJsonPath('data.retention_source', 'project_retention_pct')
            ->assertJsonPath('data.checks.8.key', 'retensi')
            ->assertJsonPath('data.checks.8.detail', fn ($detail): bool => str_contains($detail, 'Rp 2.425.000.000'));
    }

    /**
     * prj.view, same gate and same ground as the EVM and baseline GETs in the
     * same routes file: the payload quotes the Rp 2.425.000.000 at stake and
     * the unbilled termins. A teknisi holding only svc/inv permissions used to
     * read all of it with nothing but a valid token.
     */
    public function test_the_prerequisite_endpoint_is_refused_without_the_project_view_permission(): void
    {
        $this->approvedFirstHandover();
        $second = $this->submittedSecond();

        $role = Role::findOrCreate('teknisi', 'web');
        $role->syncPermissions(['svc.view', 'inv.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $teknisi */
        $teknisi = User::query()->create([
            'name' => 'Teknisi Lapangan',
            'email' => 'teknisi@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $teknisi->assignRole($role);

        $this->actingAs($teknisi)
            ->getJson("/api/projects/bast/{$second->id}/prerequisites")
            ->assertForbidden();
    }

    /**
     * INFO and not a warning, on purpose: the retention termin is unbilled BY
     * DEFINITION at the moment BAST II is approved, so making it a warning would
     * put a standing override on every single BAST II — the muted-bell failure
     * MilestoneService's docblock already names.
     */
    public function test_the_prerequisite_endpoint_lists_unbilled_termins_without_blocking(): void
    {
        $this->attachDemoContract();
        $this->approvedFirstHandover();
        $second = $this->submittedSecond();

        $checks = $this->checks($second->refresh());

        $this->assertSame('info', $checks['termin_belum_ditagih']['level']);
        $this->assertTrue($checks['termin_belum_ditagih']['passed']);
        // 14,55 M + 14,55 M + 7,275 M + 2,425 M = Rp 38.800.000.000 over 4 termins.
        $this->assertStringContainsString('4 termin belum ditagih senilai Rp 38.800.000.000', $checks['termin_belum_ditagih']['detail']);
        $this->assertTrue($this->checklist->evaluate($second)['can_approve']);
    }

    /** CTR/2026/I/0001 to the rupiah, DP already billed and the rest not. */
    private function attachDemoContract(): void
    {
        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);

        $contract = Contract::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'status' => DocumentStatus::Approved,
        ]);

        foreach ([
            [1, 'DP 20%', 20, 9_700_000_000, '2026-02-05'],
            [2, 'Progress 50%', 30, 14_550_000_000, null],
            [3, 'Progress 80%', 30, 14_550_000_000, null],
            [4, 'BAST 15%', 15, 7_275_000_000, null],
            [5, 'Retensi 5%', 5, 2_425_000_000, null],
        ] as [$no, $name, $percent, $amount, $billedAt]) {
            ContractTermin::query()->create([
                'contract_id' => $contract->id,
                'termin_no' => $no,
                'name' => $name,
                'percent' => $percent,
                'amount' => $amount,
                'billed_at' => $billedAt,
            ]);
        }

        $this->project->forceFill([
            'contract_id' => $contract->id,
            'customer_id' => $customer->id,
        ])->save();
    }

    // ------------------------------------------------ what approval writes back

    /**
     * An early BAST II approved over a warning stamps NOTHING: a date earlier
     * than the release BAST I promised is never published. "The max wins
     * downstream" used to be the whole defence, and it holds only while the
     * BAST I date exists to win — with that date nulled there was nothing to
     * max against and the early date became Finance's only date.
     */
    public function test_an_early_bast_two_never_pulls_the_retention_due_date_forward(): void
    {
        $first = $this->approvedFirstHandover();
        $second = $this->submittedSecond(['handover_date' => '2027-06-30']);

        $this->assertSame('2027-12-20', $first->retention_release_due->toDateString());

        $this->projects->approveBast(
            $second,
            $this->admin(),
            null,
            'Addendum kontrak memperpendek masa pemeliharaan menjadi 6 bulan; disetujui pemberi kerja.',
        );

        $this->assertNull($second->refresh()->retention_release_due);
        $this->assertSame(
            '2027-12-20',
            $this->retentionDueFinanceWouldRead(),
            'Finance still reads the end of the masa pemeliharaan BAST I promised',
        );
    }

    public function test_a_late_bast_two_pushes_the_retention_due_date_out_to_the_real_handover(): void
    {
        $this->approvedFirstHandover();
        $second = $this->submittedSecond(['handover_date' => '2028-02-15']);

        $this->projects->approveBast($second, $this->admin());

        $this->assertSame('2028-02-15', $this->retentionDueFinanceWouldRead());
    }

    /**
     * The hole the max could never close: with BAST I's release date nulled
     * (cleared on the draft, or warranty_months master data producing none),
     * masa_pemeliharaan reported passed=TRUE on the missing date and a BAST II
     * dated one day after BAST I silently published Rp 2.425.000.000 as
     * collectible about twelve months early. Unknown is never satisfied — the
     * absence of the date is now a warning that names the omission.
     */
    public function test_a_bast_one_without_a_release_date_fails_the_maintenance_check_instead_of_passing_silently(): void
    {
        $first = $this->approvedFirstHandover();
        $first->forceFill(['retention_release_due' => null])->save();
        $second = $this->submittedSecond(['handover_date' => '2026-12-21']);

        $checks = $this->checks($second);
        $evaluation = $this->checklist->evaluate($second);

        $this->assertSame('warning', $checks['masa_pemeliharaan']['level']);
        $this->assertFalse($checks['masa_pemeliharaan']['passed']);
        $this->assertSame('BAST I tidak mencantumkan akhir masa pemeliharaan.', $checks['masa_pemeliharaan']['detail']);
        $this->assertTrue($evaluation['can_approve'], 'master data nobody can correct is a warning, not a block');
        $this->assertTrue($evaluation['needs_override']);

        try {
            $this->projects->approveBast($second, $this->approver());
            $this->fail('Expected BastPrerequisiteException for the missing maintenance end date.');
        } catch (BastPrerequisiteException $e) {
            $this->assertStringContainsString('tidak mencantumkan akhir masa pemeliharaan', $e->getMessage());
            $this->assertStringContainsString('sertakan alasan', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $second->refresh()->status);
    }

    public function test_a_bast_two_over_a_missing_release_date_goes_through_with_a_recorded_reason_and_publishes_its_own_date(): void
    {
        $first = $this->approvedFirstHandover();
        $first->forceFill(['retention_release_due' => null])->save();
        $second = $this->submittedSecond(); // 20-12-2027, the honest final handover

        $this->projects->approveBast(
            $second,
            $this->admin(),
            null,
            'Masa pemeliharaan 12 bulan berakhir 20-12-2027 sesuai kontrak; kolom BAST I kosong karena migrasi data.',
        );

        $second->refresh();

        $this->assertSame(DocumentStatus::Approved, $second->status);
        // With no BAST I date to stand on, the approved BAST II's own handover
        // date becomes the release date: leaving it null forever would make the
        // retensi permanently uncollectible in ArRetentionService, and the gap
        // has now cost a recorded override.
        $this->assertSame('2027-12-20', $second->retention_release_due->toDateString());
    }

    /**
     * What ArRetentionService::outstanding() ends up with: it reads every
     * prj_bast row for the project ordered by retention_release_due and plucks
     * into a project-keyed map, so the LATEST date wins. The substr is its own —
     * the column comes back with a time component on SQLite.
     */
    private function retentionDueFinanceWouldRead(): string
    {
        return substr((string) Bast::query()
            ->where('project_id', $this->project->id)
            ->max('retention_release_due'), 0, 10);
    }

    /**
     * Same handoff, same audience and same argument as MilestoneService: the
     * people who hold fin.create are the smallest set who can actually raise the
     * invoice, and nobody was telling them.
     */
    public function test_approving_bast_two_tells_whoever_raises_invoices_that_the_retention_can_be_billed(): void
    {
        $role = Role::findOrCreate('penagihan', 'web');
        $role->givePermissionTo('fin.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $staff */
        $staff = User::query()->create([
            'name' => 'Staf Penagihan',
            'email' => 'penagihan@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $staff->assignRole($role);

        $this->approvedFirstHandover();
        $second = $this->submittedSecond();

        $this->projects->approveBast($second, $this->approver());

        $alert = Notification::query()
            ->where('user_id', $staff->id)
            ->where('event', Notification::SYSTEM)
            ->first();

        $this->assertNotNull($alert);
        $this->assertStringContainsString('Rp 2.425.000.000', $alert->title);
        $this->assertStringContainsString('PRJ-2026-001', $alert->title);
        $this->assertStringContainsString($second->code, $alert->body);
    }

    // ------------------------------------------------------- what is NOT gated

    /**
     * BAST I starts the masa pemeliharaan, releases nothing and has nothing yet
     * to be checked against. Gating it would refuse the very handover that makes
     * the punch list meaningful.
     */
    public function test_bast_one_is_not_gated_by_the_new_checklist(): void
    {
        $this->project->forceFill(['actual_progress_pct' => 0, 'status' => ProjectStatus::Finishing])->save();
        $this->defect(); // critical, open

        $first = $this->bast('bast1');
        $first->submit();

        $this->projects->approveBast($first, $this->approver());

        $this->assertSame(DocumentStatus::Approved, $first->refresh()->status);
        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
        $this->assertSame([], $this->checklist->evaluate($first)['checks']);
    }

    /**
     * The checklist runs only on a BAST that is already submitted. A draft must
     * still fail with the more fundamental error — the same ordering argument
     * Approvable makes for its own maker-checker guard, and the message several
     * suites assert verbatim.
     */
    public function test_a_draft_bast_two_is_still_refused_for_being_a_draft_before_any_prerequisite_is_read(): void
    {
        $draft = $this->bast('bast2', ['handover_date' => '2027-12-20']); // no BAST I anywhere

        try {
            $this->projects->approveBast($draft, $this->approver());
            $this->fail('Expected a draft refusal.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('while status is draft', $e->getMessage());
            $this->assertNotInstanceOf(BastPrerequisiteException::class, $e);
        }
    }

    /** The gate is an extra guard, never a replacement for maker-checker. */
    public function test_the_gate_does_not_bypass_maker_checker(): void
    {
        $this->approvedFirstHandover();

        $second = $this->bast('bast2', ['handover_date' => '2027-12-20']);
        $second->submit($this->admin());

        $this->expectException(SelfApprovalException::class);

        $this->projects->approveBast($second, $this->admin());
    }

    /**
     * schema.js and custom.js are off limits, so the refusal message IS the user
     * interface. It has to arrive as a 422 naming the items that block it — a
     * 500, or a generic "cannot approve", leaves the operator with nothing to do
     * next.
     */
    public function test_the_refusal_reaches_the_operator_as_a_422_naming_the_defects_that_block_it(): void
    {
        $this->approvedFirstHandover();
        $blocker = $this->defect();
        $second = $this->submittedSecond();

        $this->actingAs($this->admin())
            ->postJson("/api/projects/bast/{$second->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, $second->code)
                && str_contains($message, $blocker->code)
                && str_contains($message, 'belum dapat disetujui'))
            ->assertJsonPath('errors.prerequisites.0.key', 'defect_berat')
            ->assertJsonPath('errors.prerequisites.0.level', 'block');

        $this->assertSame(DocumentStatus::Submitted, $second->refresh()->status);
        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }
}
