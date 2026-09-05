<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Core\Support\SegregationOfDuties;
use Modules\Finance\Models\ApBill;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WorkPermit;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Maker-checker for a document that reached `submitted` without a submit
 * trail (T3.4, ANALISIS-PROSES §3 C3).
 *
 * Measured on production 4 Sep 2026 (HASIL-UJI §6 P-3): PR/2026/III/0002 had
 * been seeded straight to `submitted` with no core_approvals row, its detail
 * read "Diminta oleh admin", and admin approved it from the dashboard card in
 * one click — `approvals: []` before and after. SegregationOfDuties reads the
 * `submitted` row, and there was none to read.
 *
 * Pins the fallback and its edges: when NO submission was ever recorded, the
 * table's owner column names the maker (requested_by on a PR — a users.id;
 * requested_by on a work permit — an EMPLOYEE number resolved through
 * users.employee_id); a submission recorded as nobody is NOT second-guessed
 * (the RetentionService / AdvanceService path); and a table with no owner
 * column at all (fin_ap_bills, the retention-release bill) is untouched.
 */
class MakerCheckerOwnerFallbackTest extends ErpTestCase
{
    // -------------------------------------------------------------- fixtures

    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang '.implode(' ', $permissions),
            'email' => substr(md5(implode('|', $permissions).microtime()), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** The production shape: status written directly, no core_approvals row. */
    private function seededSubmittedPr(User $requester): PurchaseRequisition
    {
        return PurchaseRequisition::query()->create([
            'needed_date' => '2026-04-01',
            'status' => 'submitted',
            'purpose' => 'Material ELV/ICT tahap 1 — seed langsung submitted',
            'requested_by' => $requester->id,
        ]);
    }

    private function bill(array $overrides = []): ApBill
    {
        $vendor = Vendor::query()->firstOr(fn () => Vendor::query()->create([
            'name' => 'CV Baja Mandiri',
            'is_subcontractor' => true,
            'classification' => 'material',
            'status' => 'active',
        ]));

        return ApBill::query()->create(array_merge([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-05-10',
            'due_date' => '2026-06-09',
            'description' => 'Pelepasan retensi SPK/2026/III/0001',
            'vendor_invoice_no' => '',
            'dpp' => 5_000_000,
            'total_payable' => 5_000_000,
            'amount_paid' => 0,
            'status' => 'draft',
        ], $overrides));
    }

    private function approve(User $as, PurchaseRequisition $pr)
    {
        return $this->actingAs($as)
            ->postJson("/api/procurement/purchase-requisitions/{$pr->id}/approve");
    }

    // ------------------------------------------------- the production case

    public function test_a_pr_seeded_straight_to_submitted_is_refused_to_its_own_requester(): void
    {
        $requester = $this->userWith('prc.approve');
        $pr = $this->seededSubmittedPr($requester);

        $this->assertSame(0, $pr->approvals()->count(), 'the seed wrote no trail — that is the case');

        $response = $this->approve($requester, $pr);

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        // The existing named-person refusal, so the operator knows WHO to find.
        $this->assertStringContainsString(
            "Permintaan pembelian {$pr->code} diajukan oleh {$requester->name}; dokumen tidak boleh disetujui oleh pengajunya sendiri.",
            $message,
        );
        $this->assertStringContainsString('pemegang izin prc.approve', $message);

        $this->assertSame(DocumentStatus::Submitted, $pr->fresh()->status);
        $this->assertSame(0, $pr->approvals()->count(), 'a refusal leaves no footprint');
    }

    public function test_the_same_pr_is_approved_by_a_second_pair_of_eyes(): void
    {
        $requester = $this->userWith('prc.approve');
        $other = $this->userWith('prc.approve', 'prc.view');
        $pr = $this->seededSubmittedPr($requester);

        $this->approve($other, $pr)->assertOk();

        $this->assertSame(DocumentStatus::Approved, $pr->fresh()->status);
        $this->assertSame(['approved'], $pr->approvals()->pluck('action')->all());
    }

    public function test_the_switch_off_lets_the_requester_through_as_it_does_for_a_submitter(): void
    {
        $this->setSetting('approvals.segregation_of_duties', false);

        $requester = $this->userWith('prc.approve');
        $pr = $this->seededSubmittedPr($requester);

        $this->approve($requester, $pr)->assertOk();

        $this->assertSame(DocumentStatus::Approved, $pr->fresh()->status);
    }

    // ------------------------------------------------------------- the edges

    /**
     * A recorded row wins over the column. Alice requested it, Bob clicked
     * Ajukan: Bob asserted it, so Bob is refused and Alice may approve —
     * exactly what the guard did before the fallback existed.
     */
    public function test_a_recorded_submission_beats_the_owner_column(): void
    {
        $alice = $this->userWith('prc.approve');
        $bob = $this->userWith('prc.approve', 'prc.create');

        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-04-01',
            'status' => 'draft',
            'purpose' => 'Diminta Alice, diajukan Bob',
            'requested_by' => $alice->id,
        ]);
        $pr->submit($bob);

        $this->assertSame($bob->id, SegregationOfDuties::makerIdOf($pr));
        $this->approve($bob, $pr)->assertStatus(422);
        $this->approve($alice, $pr)->assertOk();
    }

    /**
     * submit(null) is a recorded state — the engine asserted the submission on
     * purpose (RetentionService, AdvanceService) — and stays approvable by
     * anyone. The fallback fires only when NOTHING was recorded; a row whose
     * actor is nobody is not the same as no row.
     */
    public function test_a_submission_recorded_as_nobody_is_not_second_guessed_by_the_owner_column(): void
    {
        $requester = $this->userWith('prc.approve');

        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-04-01',
            'status' => 'draft',
            'purpose' => 'Diajukan oleh mesin',
            'requested_by' => $requester->id,
        ]);
        $pr->submit(null);

        $this->assertNull(SegregationOfDuties::makerIdOf($pr));
        $this->approve($requester, $pr)->assertOk();
    }

    /**
     * prj_work_permits.requested_by is an hr_employees id, not a users.id (its
     * migration says so — "pemohon adalah pegawai"). The mandor with employee
     * #1 is user #2; user #1 is somebody else entirely. A name-only rule would
     * refuse user #1 and wave the mandor's own login through.
     */
    public function test_a_work_permit_owner_is_an_employee_number_resolved_to_its_own_login(): void
    {
        // The user comes first and the employee takes ITS id: the fixture is
        // users.id == hr_employees.id. It used to rely on both tables handing
        // out the same next number, which SQLite :memory: did and MySQL does
        // not — auto-increment counters there never rewind between tests
        // (user #158 vs employee #15 on phpunit.mysql.xml, 5 Sep 2026).
        $collision = $this->userWith('prj.approve');

        $employee = Employee::query()->forceCreate([
            'id' => $collision->id,
            'code' => 'EMP-7001',
            'name' => 'Sutrisno Hadi',
            'nik_ktp' => '3216012504780001',
            'gender' => 'male',
            'birth_date' => '1978-04-25',
            'ptkp_status' => 'K/2',
            'join_date' => '2021-01-04',
            'employment_type' => 'tetap',
            'position' => 'Mandor Sipil',
            'department' => 'proyek',
            'base_salary' => 7_500_000,
        ]);

        $this->assertSame((int) $employee->id, (int) $collision->id, 'the fixture needs the collision to mean anything');

        $mandorLogin = $this->userWith('prj.approve', 'prj.view');
        $mandorLogin->forceFill(['employee_id' => $employee->id])->save();

        $project = Project::query()->create([
            'code' => 'PRJ-2026-081',
            'name' => 'Gedung Serbaguna Karawang',
            'type' => 'construction',
            'status' => 'active',
        ]);
        $permit = WorkPermit::query()->create([
            'project_id' => $project->id,
            'permit_date' => '2026-06-15',
            'shift' => 'pagi',
            'work_description' => 'Pengecoran kolom lantai 3 zona B',
            'valid_from' => '2026-06-15 08:00:00',
            'valid_until' => '2026-06-15 17:00:00',
            'requested_by' => $employee->id,
            'status' => 'submitted',
        ]);

        $this->assertSame($mandorLogin->id, SegregationOfDuties::makerIdOf($permit));

        SegregationOfDuties::assertNotSubmitter($permit, $collision); // passes

        $this->expectException(SelfApprovalException::class);
        SegregationOfDuties::assertNotSubmitter($permit, $mandorLogin);
    }

    /**
     * The retention-release bill in miniature. fin_ap_bills carries none of
     * the owner columns, so the fallback has nothing to read: a bill submitted
     * as nobody — and even one seeded straight to `submitted` — is approvable
     * by anyone holding fin.approve, as RetentionReleaseGateTest proves end to
     * end.
     */
    public function test_a_bill_has_no_owner_column_so_the_fallback_cannot_fire(): void
    {
        foreach (['requested_by', 'created_by', 'submitted_by'] as $column) {
            $this->assertFalse(Schema::hasColumn('fin_ap_bills', $column), "fin_ap_bills.{$column} must not exist for this pin to hold");
        }

        $releaser = $this->userWith('fin.approve');

        $released = $this->bill();
        $released->submit(null);
        $this->assertNull(SegregationOfDuties::makerIdOf($released));
        $released->approve($releaser, 'Pelepasan retensi');
        $this->assertSame(DocumentStatus::Approved, $released->fresh()->status);

        $seeded = $this->bill(['status' => 'submitted', 'description' => 'Seed langsung submitted']);
        $this->assertNull(SegregationOfDuties::makerIdOf($seeded));
        $seeded->approve($releaser);
        $this->assertSame(DocumentStatus::Approved, $seeded->fresh()->status);
    }
}
