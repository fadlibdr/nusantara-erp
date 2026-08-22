<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectSource;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Exceptions\ProjectClosureException;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\DailyReportService;
use Modules\Projects\Services\DefectService;
use Modules\Projects\Services\ProgressService;
use Modules\Projects\Services\ProjectClosureService;
use Modules\Projects\Services\ProjectService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Tutup proyek — the explicit action, and the doors it closes.
 *
 * Until now "Ditutup" was one option in a free dropdown: anybody holding
 * prj.update could jump PRJ-2026-001 straight to closed while termin 4
 * "BAST 15%" (Rp 7,275 M) and termin 5 retensi (Rp 2,425 M) sat unbilled —
 * Rp 9,7 miliar of hak tagih with nothing in the way and nobody told. And
 * because ProjectStatus::isOperational() was declared but never called, daily
 * reports and progress kept flowing into the closed project afterwards, so the
 * period's cost and progress reports could not be trusted either.
 *
 * Closing is now an action with a checklist (open defects, open POs, unbilled
 * termins, unreleased retention) behind prj.approve; the dropdown can no longer
 * reach "Ditutup"; and the field-entry services refuse a project that is not
 * operational. BAST II keeps its own gate — BastPrerequisiteService — which is
 * stricter about handover order; this checklist is the one for closing WITHOUT
 * a handover ceremony (proyek batal, kontrak putus, rapikan data lama).
 */
class ProjectClosureTest extends ErpTestCase
{
    private ProjectService $projects;

    private ProjectClosureService $closure;

    private DefectService $defects;

    private Project $project;

    private ?User $admin = null;

    private ?User $approver = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->projects = app(ProjectService::class);
        $this->closure = app(ProjectClosureService::class);
        $this->defects = app(DefectService::class);
        $this->project = $this->makeProject();
    }

    // -------------------------------------------------------------- fixtures

    /** PRJ-2026-001 on the day closing is genuinely due: warranty served, WBS at 100%. */
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

    /** Holds prj.approve — closing is an approval-grade act. */
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

    /** Site staff: may enter and edit, may never close. */
    private function pelaksana(): User
    {
        $role = Role::findOrCreate('pelaksana', 'web');
        $role->syncPermissions(['prj.view', 'prj.create', 'prj.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pelaksana Lapangan',
            'email' => 'pelaksana@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
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

    /** One open PO against the project — Rp 250 juta of semen still on order. */
    private function openPurchaseOrder(string $code = 'PO/2026/XI/0031', string $status = 'approved'): void
    {
        $vendorId = DB::table('prc_vendors')->insertGetId([
            'code' => 'VND-0001',
            'name' => 'PT Semen Andalan',
            'classification' => 'material',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prc_purchase_orders')->insert([
            'code' => $code,
            'vendor_id' => $vendorId,
            'project_id' => $this->project->id,
            'order_date' => '2026-11-05',
            'subtotal' => 250_000_000,
            'dpp' => 250_000_000,
            'total' => 250_000_000,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** CTR/2026/I/0001's tail: termin 4 (7,275 M) and 5 (2,425 M) still unbilled. */
    private function attachContractWithUnbilledTermins(): Contract
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

        return $contract;
    }

    /** Rp 727,5 juta genuinely withheld on an issued invoice, not yet released. */
    private function withholdRetentionOnAnInvoice(): void
    {
        $contract = $this->project->contract_id
            ? Contract::query()->find($this->project->contract_id)
            : $this->attachContractWithUnbilledTermins();

        $invoiceId = DB::table('fin_ar_invoices')->insertGetId([
            'code' => 'INV/2026/VI/0004',
            'customer_id' => $this->project->customer_id,
            'contract_id' => $contract->id,
            'project_id' => $this->project->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'description' => 'Termin progres 50%',
            'dpp' => 14_550_000_000,
            'total' => 14_550_000_000,
            'terbilang' => 'empat belas miliar lima ratus lima puluh juta rupiah',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fin_ar_retentions')->insert([
            'contract_id' => $contract->id,
            'project_id' => $this->project->id,
            'source_invoice_id' => $invoiceId,
            'amount' => 727_500_000,
            'released' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private const REASON = 'Kontrak diputus pemberi kerja; sisa termin diselesaikan lewat kesepakatan akhir 12-01-2028.';

    // ------------------------------------------------------- the clean close

    public function test_a_clean_project_closes_and_the_click_is_stamped_with_what_was_true(): void
    {
        $response = $this->actingAs($this->approver())
            ->postJson("/api/projects/{$this->project->id}/close");

        $response->assertOk();

        $closed = $this->project->refresh();

        $this->assertSame(ProjectStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($this->approver()->id, $closed->closed_by);
        $this->assertNull($closed->closure_override_reason, 'a clean close records no override');

        $snapshot = $closed->closure_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertTrue($snapshot['can_close']);
        $this->assertEqualsCanonicalizing(
            ['belum_ditutup', 'defect_berat', 'defect_minor', 'po_terbuka', 'termin_belum_ditagih', 'retensi_belum_cair'],
            array_column($snapshot['checks'], 'key'),
        );
    }

    public function test_closing_needs_the_approve_permission_not_merely_update(): void
    {
        $this->actingAs($this->pelaksana())
            ->postJson("/api/projects/{$this->project->id}/close")
            ->assertForbidden();

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    public function test_a_closed_project_cannot_be_closed_twice(): void
    {
        $this->closure->close($this->project, $this->approver());

        $this->actingAs($this->approver())
            ->postJson("/api/projects/{$this->project->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'sudah berstatus Ditutup'));
    }

    // ------------------------------------------------------ the open items

    /** The summary an approver reads BEFORE clicking — every open item named. */
    public function test_the_closure_summary_names_every_open_item(): void
    {
        $blocker = $this->defect();
        $this->openPurchaseOrder();
        $this->attachContractWithUnbilledTermins();

        $response = $this->actingAs($this->approver())
            ->getJson("/api/projects/{$this->project->id}/closure")
            ->assertOk()
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.needs_override', true);

        $checks = collect($response->json('data.checks'))->keyBy('key');

        $this->assertFalse($checks['defect_berat']['passed']);
        $this->assertStringContainsString($blocker->code, $checks['defect_berat']['detail']);
        $this->assertFalse($checks['po_terbuka']['passed']);
        $this->assertStringContainsString('PO/2026/XI/0031', $checks['po_terbuka']['detail']);
        $this->assertFalse($checks['termin_belum_ditagih']['passed']);
        // 7,275 M + 2,425 M — the Rp 9,7 miliar the audit leads with.
        $this->assertStringContainsString('Rp 9.700.000.000', $checks['termin_belum_ditagih']['detail']);
    }

    public function test_the_closure_summary_is_refused_without_the_project_view_permission(): void
    {
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
            ->getJson("/api/projects/{$this->project->id}/closure")
            ->assertForbidden();
    }

    public function test_closing_is_refused_while_a_critical_defect_is_open(): void
    {
        $blocker = $this->defect();

        $this->actingAs($this->approver())
            ->postJson("/api/projects/{$this->project->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, $blocker->code)
                && str_contains($message, 'belum dapat ditutup'))
            ->assertJsonPath('errors.closure.0.key', 'defect_berat')
            ->assertJsonPath('errors.closure.0.level', 'block');

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    /**
     * A PO still open at closing means committed spend with no receipt: the cost
     * either arrives after the project stopped being watched, or never arrives
     * and the commitment rots. Satisfiable in one act — receive it or cancel it
     * — so it blocks, per the line BastPrerequisiteService draws.
     */
    public function test_closing_is_refused_while_a_purchase_order_is_open(): void
    {
        $this->openPurchaseOrder();

        $this->actingAs($this->approver())
            ->postJson("/api/projects/{$this->project->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'PO/2026/XI/0031'));

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    /**
     * Rp 9,7 miliar of unbilled termins WARNS rather than blocks: a putus-kontrak
     * settlement genuinely leaves termins that will never be billed, and a block
     * nobody can satisfy is a block people learn to route around. But it can no
     * longer pass in silence — the audit's whole complaint.
     */
    public function test_unbilled_termins_hold_the_close_until_a_reason_is_recorded(): void
    {
        $this->attachContractWithUnbilledTermins();

        $this->actingAs($this->approver())
            ->postJson("/api/projects/{$this->project->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'termin belum ditagih')
                && str_contains($message, 'sertakan alasan'));

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);

        $this->actingAs($this->approver())
            ->postJson("/api/projects/{$this->project->id}/close", ['override_reason' => self::REASON])
            ->assertOk();

        $closed = $this->project->refresh();

        $this->assertSame(ProjectStatus::Closed, $closed->status);
        $this->assertSame(self::REASON, $closed->closure_override_reason);
    }

    public function test_a_reason_shorter_than_a_sentence_is_refused(): void
    {
        $this->attachContractWithUnbilledTermins();

        $this->actingAs($this->approver())
            ->postJson("/api/projects/{$this->project->id}/close", ['override_reason' => 'sudah oke'])
            ->assertStatus(422);

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    public function test_retention_still_withheld_on_invoices_holds_the_close_until_a_reason_is_recorded(): void
    {
        $this->withholdRetentionOnAnInvoice();
        // The termins warn too; bill them so THIS warning is the one under test.
        ContractTermin::query()->update(['billed_at' => '2027-12-20']);

        try {
            $this->closure->close($this->project->refresh(), $this->approver());
            $this->fail('Expected ProjectClosureException for unreleased retention.');
        } catch (ProjectClosureException $e) {
            $this->assertStringContainsString('Rp 727.500.000', $e->getMessage());
            $this->assertStringContainsString('retensi', $e->getMessage());
        }

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    /** An override reason lifts warnings only — never the blocks. */
    public function test_an_override_reason_cannot_lift_an_open_purchase_order(): void
    {
        $this->openPurchaseOrder();

        try {
            $this->closure->close($this->project, $this->approver(), self::REASON);
            $this->fail('Expected the open PO to survive an override reason.');
        } catch (ProjectClosureException $e) {
            $this->assertStringContainsString('PO/2026/XI/0031', $e->getMessage());
        }

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    // ------------------------------------------------- the dropdown is shut

    public function test_the_status_dropdown_can_no_longer_reach_closed(): void
    {
        $this->actingAs($this->admin())
            ->putJson("/api/projects/{$this->project->id}", ['status' => 'closed'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'Tutup proyek'));

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    public function test_ordinary_status_moves_still_work(): void
    {
        $this->actingAs($this->admin())
            ->putJson("/api/projects/{$this->project->id}", ['status' => 'on_hold'])
            ->assertOk();

        $this->assertSame(ProjectStatus::OnHold, $this->project->refresh()->status);
    }

    /**
     * The SPA's edit form uses the projectStatusEditable list, which has no
     * 'Ditutup' option — so editing a CLOSED project shows an empty select and
     * the PUT carries status: null. Null must read as "leave the status alone":
     * failing validation there would make every typo-fix on a closed project
     * impossible, and writing the null would crash on the NOT NULL column.
     */
    public function test_a_null_status_on_update_leaves_the_status_untouched(): void
    {
        $this->closure->close($this->project, $this->approver());

        $this->actingAs($this->admin())
            ->putJson("/api/projects/{$this->project->id}", ['status' => null, 'city' => 'Jakarta Selatan'])
            ->assertOk();

        $updated = $this->project->refresh();

        $this->assertSame(ProjectStatus::Closed, $updated->status, 'an empty select is not a status change');
        $this->assertSame('Jakarta Selatan', $updated->city);
    }

    /**
     * Reopening is DELIBERATELY left to the ordinary update: closing was a
     * mistake often enough (the wrong project, a settlement that fell through)
     * and there is no undo anywhere else. The asymmetry is the protection —
     * closing takes prj.approve plus a checklist, reopening is one visible
     * status change that any later close must re-earn through the checklist.
     */
    public function test_a_closed_project_can_be_reopened_by_ordinary_update(): void
    {
        $this->closure->close($this->project, $this->approver());

        $this->actingAs($this->admin())
            ->putJson("/api/projects/{$this->project->id}", ['status' => 'warranty'])
            ->assertOk();

        $this->assertSame(ProjectStatus::Warranty, $this->project->refresh()->status);
    }

    // ------------------------------------ isOperational finally does its job

    /**
     * Pintu yang paling keras justru sempat tidak dijaga: generate-wbs pada
     * proyek TUTUP menjawab 200, menghapus dan membangun ulang seluruh WBS,
     * dan me-reset actual_progress_pct 100 -> 0 — tulisan yang lebih ganas
     * daripada entri progres mana pun yang penjaga ini tolak.
     */
    public function test_a_closed_project_refuses_wbs_regeneration(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Closed, 'actual_progress_pct' => 100])->save();

        try {
            app(ProjectService::class)->generateWbsFromBoq($this->project->fresh());
            $this->fail('Expected LogicException for WBS regeneration on a closed project.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Ditutup', $e->getMessage());
        }

        $this->assertSame(100.0, (float) $this->project->fresh()->actual_progress_pct);
    }

    /**
     * delete() mengikuti rasional update()-nya sendiri: laporan harian pada
     * proyek tutup adalah riwayat, bukan draf — dan riwayat tidak dihapus.
     */
    public function test_a_closed_project_refuses_deleting_its_old_daily_reports(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Active])->save();

        $report = app(DailyReportService::class)->create([
            'project_id' => $this->project->id,
            'report_date' => '2027-01-05',
            'activities' => 'Pekerjaan pondasi',
        ]);

        $this->project->forceFill(['status' => ProjectStatus::Closed])->save();

        try {
            app(DailyReportService::class)->delete($report->fresh());
            $this->fail('Expected LogicException for deleting a daily report on a closed project.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('laporan harian', $e->getMessage());
        }

        $this->assertSame(1, $this->project->dailyReports()->count());
    }

    public function test_a_closed_project_refuses_daily_reports(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Closed])->save();

        try {
            app(DailyReportService::class)->create([
                'project_id' => $this->project->id,
                'report_date' => '2028-01-05',
                'activities' => 'Perapian sisa material',
            ]);
            $this->fail('Expected LogicException for a daily report on a closed project.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('laporan harian', $e->getMessage());
            $this->assertStringContainsString('Ditutup', $e->getMessage());
        }

        $this->assertSame(0, $this->project->dailyReports()->count());
    }

    public function test_the_refusal_reaches_the_operator_as_a_422_not_a_500(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Closed])->save();

        $this->actingAs($this->admin())
            ->postJson('/api/projects/daily-reports', [
                'project_id' => $this->project->id,
                'report_date' => '2028-01-05',
                'manpower_count' => 4,
                'activities' => 'Perapian sisa material',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message): bool => str_contains($message, 'laporan harian'));
    }

    public function test_an_operational_project_still_takes_daily_reports(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Active])->save();

        $report = app(DailyReportService::class)->create([
            'project_id' => $this->project->id,
            'report_date' => '2026-08-06',
            'activities' => 'Pengecoran plat lantai 5 zona B',
        ]);

        $this->assertNotNull($report->id);
    }

    /**
     * Warranty refuses too — isOperational() is the line the enum has documented
     * since day one: site DATA ENTRY belongs to Persiapan/Berjalan/Finishing.
     * Perbaikan defect during masa pemeliharaan is recorded on the defect
     * register (which stays writable), not as laporan harian progress.
     */
    public function test_a_warranty_project_refuses_daily_reports_as_the_enum_always_promised(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Masa Pemeliharaan/');

        app(DailyReportService::class)->create([
            'project_id' => $this->project->id,
            'report_date' => '2027-06-05',
            'activities' => 'Perbaikan minor',
        ]);
    }

    public function test_a_closed_project_refuses_edits_to_its_old_daily_reports(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Active])->save();
        $report = app(DailyReportService::class)->create([
            'project_id' => $this->project->id,
            'report_date' => '2026-08-06',
            'activities' => 'Pengecoran plat lantai 5 zona B',
        ]);
        $this->project->forceFill(['status' => ProjectStatus::Closed])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/laporan harian/');

        app(DailyReportService::class)->update($report, ['activities' => 'Diubah setelah tutup']);
    }

    public function test_a_closed_project_refuses_wbs_progress(): void
    {
        $task = $this->project->wbsTasks()->create([
            'wbs_code' => 'B.2',
            'name' => 'Beton ready mix K-300 kolom, balok & plat',
            'weight_pct' => 100,
            'progress_pct' => 90,
        ]);
        $this->project->forceFill(['status' => ProjectStatus::Closed])->save();

        try {
            app(ProgressService::class)->updateTaskProgress($task, 100.0);
            $this->fail('Expected LogicException for WBS progress on a closed project.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('progres', $e->getMessage());
        }

        $this->assertEquals(90.0, (float) $task->refresh()->progress_pct, 'the entry must not land');
    }

    public function test_a_closed_project_refuses_weekly_progress(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Closed])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/progres mingguan/');

        app(ProgressService::class)->recordWeekly([
            'project_id' => $this->project->id,
            'week_no' => 40,
            'period_start' => '2028-01-03',
            'period_end' => '2028-01-09',
            'planned_pct' => 100,
            'actual_pct' => 100,
        ]);
    }

    // --------------------------------------------------- BAST II unaffected

    /**
     * BAST II keeps closing the project through its own, stricter gate — and the
     * new columns record when and by whom, so "kapan proyek ini ditutup" has one
     * answer whichever door closed it. The checklist BAST II ran is on the BAST
     * (prerequisite_snapshot); closure_snapshot stays null on that path.
     */
    public function test_bast_two_still_closes_the_project_and_now_stamps_when_and_by_whom(): void
    {
        $first = $this->projects->createBast([
            'project_id' => $this->project->id,
            'bast_type' => 'bast1',
            'handover_date' => '2026-12-20',
            'customer_representative' => 'Ir. Bambang (Owner Rep.)',
        ]);
        $first->submit();
        $this->projects->approveBast($first, $this->approver());

        $second = $this->projects->createBast([
            'project_id' => $this->project->id,
            'bast_type' => 'bast2',
            'handover_date' => '2027-12-20',
            'customer_representative' => 'Ir. Bambang (Owner Rep.)',
        ]);
        $second->submit();
        $this->projects->approveBast($second, $this->admin());

        $closed = $this->project->refresh();

        $this->assertSame(ProjectStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($this->admin()->id, $closed->closed_by);
        $this->assertNull($closed->closure_snapshot, 'the BAST II checklist lives on the BAST, not here');
    }
}
