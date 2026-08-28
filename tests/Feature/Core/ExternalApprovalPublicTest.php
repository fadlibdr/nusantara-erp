<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\ExternalApproval;
use Modules\Core\Models\Notification;
use Modules\Core\Services\ExternalApprovalService;
use Modules\Crm\Services\ContractChangeOrderService;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WorkPermit;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * P0-F prerequisite — persetujuan eksternal, sisi PUBLIK (tanpa login).
 *
 * MK/Owner membuka /persetujuan/{token} di ponselnya dan memutuskan: setuju /
 * setuju dengan catatan / tolak. Yang dijaga file ini:
 *
 *  - halaman menampilkan ringkasan dokumen dan tiga tombol; token yang tidak
 *    dikenal mendapat halaman jujur yang tidak membocorkan apa pun;
 *  - SEKALI-PAKAI: keputusan menutup token di dalam transaksi dengan baca
 *    ulang terkunci — dua klik pada tautan yang sama tidak mencatat dua kali,
 *    dan klik kedua melihat struk keputusan pertama, bukan formulir;
 *  - kedaluwarsa diukur di tepi (expires_at = sekarang → ditolak); dicabut
 *    ditolak;
 *  - mode record: laporan harian TERKUNCI pada keputusan PERTAMA saja, CCO
 *    tidak bertransisi (proksi internal tetap penyetujunya — keputusan #6);
 *  - mode transisi: keputusan eksternal MENGGERAKKAN izin kerja lewat adapter
 *    service atas nama penerbit tautan, dan maker-checker tetap berdiri —
 *    tautan terbitan pengaju sendiri ditolak saat diterapkan;
 *  - pemegang {prefix}.approve diberi tahu, dengan tautan ke dokumennya;
 *  - kedua rute publik berpagar throttle:10,1 (preseden login).
 */
class ExternalApprovalPublicTest extends ErpTestCase
{
    use FinanceFixtures;

    private ExternalApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ExternalApprovalService::class);
    }

    // -------------------------------------------------------------- fixtures

    private function approver(string $email = 'penerbit@test.local', array $permissions = ['prj.view', 'prj.approve']): User
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::findOrCreate('r-'.md5($email), 'web');
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => 'Penerbit '.$email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function maker(): User
    {
        return User::query()->firstOrCreate(['email' => 'pengaju@test.local'], [
            'name' => 'Agus Prasetyo',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-092',
            'name' => 'Renovasi Gudang Cakung',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'warranty_months' => 12,
        ]);
    }

    private function report(Project $project, string $date = '2026-06-10'): DailyReport
    {
        return DailyReport::query()->create([
            'project_id' => $project->id,
            'report_date' => $date,
            'manpower_count' => 42,
            'activities' => 'Pengecoran pelat lantai 5 zona A',
        ]);
    }

    private function submittedPermit(Project $project, User $maker): WorkPermit
    {
        $employee = Employee::query()->firstOrCreate(['code' => 'EMP-7102'], [
            'name' => 'Sutrisno Hadi',
            'nik_ktp' => '3216012504780003',
            'gender' => 'male',
            'birth_date' => '1978-04-25',
            'ptkp_status' => 'K/2',
            'join_date' => '2021-01-04',
            'employment_type' => 'tetap',
            'position' => 'Mandor Sipil',
            'department' => 'proyek',
            'base_salary' => 7_500_000,
        ]);

        $permit = WorkPermit::query()->create([
            'project_id' => $project->id,
            'permit_date' => '2026-06-15',
            'shift' => 'pagi',
            'work_description' => 'Pengecoran kolom lantai 3 zona B',
            'hazard_notes' => 'Bekerja di ketinggian',
            'ppe_required' => ['Helm proyek'],
            'valid_from' => '2026-06-15 08:00',
            'valid_until' => '2026-06-15 17:00',
            'requested_by' => $employee->id,
            'status' => DocumentStatus::Draft,
        ]);

        return $permit->submit($maker);
    }

    /** @return array{0: ExternalApproval, 1: string} the row and the plaintext token */
    private function issue(string $slug, int $id, array $overrides = [], ?User $by = null): array
    {
        $issued = $this->service->issue($by ?? $this->approver(), array_merge([
            'document_type' => $slug,
            'document_id' => $id,
            'party' => 'mk',
            'name' => 'Ir. Handoko Wibawa',
            'organization' => 'PT MK Nusantara Konsultan',
        ], $overrides));

        return [$issued['approval'], $issued['token']];
    }

    // ------------------------------------------------------------- the page

    public function test_a_live_token_shows_the_document_summary_and_three_decisions(): void
    {
        $report = $this->report($this->project());
        [, $token] = $this->issue('projects/daily-reports', $report->id);

        $page = $this->get("/persetujuan/{$token}")->assertOk();

        $page->assertSee($report->code);
        $page->assertSee('PRJ-2026-092');
        $page->assertSee('Setuju');
        $page->assertSee('Setuju dengan catatan');
        $page->assertSee('Tolak');
    }

    public function test_an_unknown_token_gets_an_honest_page_that_leaks_nothing(): void
    {
        $report = $this->report($this->project());
        $this->issue('projects/daily-reports', $report->id);

        $page = $this->get('/persetujuan/'.str_repeat('z', 40));

        $page->assertNotFound();
        $page->assertDontSee($report->code);
        $this->assertStringContainsString('tidak dikenal', mb_strtolower($page->getContent()));
    }

    public function test_each_decision_value_is_recorded_and_answered_with_a_receipt(): void
    {
        $report = $this->report($this->project());

        foreach ([
            'approved' => 'Setuju',
            'approved_with_notes' => 'Setuju dengan catatan',
            'rejected' => 'Tolak',
        ] as $decision => $label) {
            [$row, $token] = $this->issue('projects/daily-reports', $report->id, ['party' => 'owner']);

            $answer = $this->post("/persetujuan/{$token}", [
                'decision' => $decision,
                'notes' => $decision === 'approved' ? '' : 'Catatan dari lapangan.',
            ])->assertOk();

            $row->refresh();
            $this->assertSame($decision, $row->decision?->value);
            $this->assertSame('link', $row->decided_via);
            $this->assertNotNull($row->decided_at);
            $answer->assertSee($label);
        }
    }

    // ------------------------------------------------------------ single use

    public function test_a_token_is_single_use_and_the_second_click_sees_a_receipt_not_a_form(): void
    {
        $report = $this->report($this->project());
        [$row, $token] = $this->issue('projects/daily-reports', $report->id);

        $this->post("/persetujuan/{$token}", ['decision' => 'approved'])->assertOk();
        $decidedAt = $row->refresh()->decided_at;

        // The second click — same link, opposite decision. Nothing may change.
        $second = $this->post("/persetujuan/{$token}", ['decision' => 'rejected'])->assertOk();

        $row->refresh();
        $this->assertSame('approved', $row->decision?->value, 'the first decision stands');
        $this->assertEquals($decidedAt, $row->decided_at);
        $second->assertSee('Setuju');
        $second->assertDontSee('name="decision"', false);

        // And the GET after use is the receipt too, never the form again.
        $this->get("/persetujuan/{$token}")->assertOk()->assertDontSee('name="decision"', false);
    }

    public function test_the_race_is_lost_on_the_locked_reread_not_on_the_stale_instance(): void
    {
        $report = $this->report($this->project());
        [$row, $token] = $this->issue('projects/daily-reports', $report->id);

        // First actor reads the row; second actor decides on an instance of
        // their own; the first actor's copy still believes the link is unused.
        $stale = ExternalApproval::query()->findOrFail($row->id);
        $this->service->decide($token, 'approved', null);
        $this->assertNull($stale->decided_at);

        try {
            $this->service->decide($token, 'rejected', null);
            $this->fail('the second decide must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah digunakan', $e->getMessage());
        }

        $this->assertSame('approved', $row->refresh()->decision?->value);
    }

    // -------------------------------------------------------- expiry, revoke

    public function test_expiry_is_enforced_at_the_exact_boundary(): void
    {
        $report = $this->report($this->project());
        [$row, $token] = $this->issue('projects/daily-reports', $report->id);

        $this->travelTo($row->expires_at);

        $this->assertStringContainsString('kedaluwarsa', mb_strtolower($this->get("/persetujuan/{$token}")->getContent()));
        $this->post("/persetujuan/{$token}", ['decision' => 'approved']);

        $this->assertNull($row->refresh()->decision, 'expires_at = now must already refuse');

        $this->travelBack();
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $report = $this->report($this->project());
        $issuer = $this->approver();
        [$row, $token] = $this->issue('projects/daily-reports', $report->id, [], $issuer);

        $this->service->revoke($row, $issuer);

        $this->assertStringContainsString('dicabut', mb_strtolower($this->get("/persetujuan/{$token}")->getContent()));
        $this->post("/persetujuan/{$token}", ['decision' => 'approved']);
        $this->assertNull($row->refresh()->decision);
    }

    // ------------------------------------------------------------------ hooks

    public function test_the_first_decision_locks_the_daily_report_and_the_second_does_not_move_the_clock(): void
    {
        $project = $this->project();
        $report = $this->report($project);
        [, $mkToken] = $this->issue('projects/daily-reports', $report->id, ['party' => 'mk']);
        [, $ownerToken] = $this->issue('projects/daily-reports', $report->id, ['party' => 'owner', 'name' => 'Dr. Ratna Sari']);

        $this->travelTo(now()->startOfSecond());
        $this->post("/persetujuan/{$mkToken}", ['decision' => 'approved'])->assertOk();
        $lockedAt = $report->refresh()->locked_at;
        $this->assertNotNull($lockedAt, 'the FIRST external decision locks the report');

        $this->travel(2)->hours();
        $this->post("/persetujuan/{$ownerToken}", ['decision' => 'approved_with_notes', 'notes' => 'Cuaca sore dilengkapi.'])->assertOk();
        $this->assertEquals($lockedAt, $report->refresh()->locked_at, 'the second decision must not restamp the lock');
        $this->travelBack();

        // The locked report refuses edits, and the refusal names the lock.
        $admin = $this->adminUser();
        Sanctum::actingAs($admin);
        $refused = $this->putJson("/api/projects/daily-reports/{$report->id}", ['manpower_count' => 50]);
        $refused->assertStatus(422);
        $this->assertStringContainsString('terkunci', (string) $refused->json('message'));
        $this->assertStringContainsString('keputusan eksternal', mb_strtolower((string) $refused->json('message')));
    }

    public function test_a_recorded_cco_decision_does_not_transition_the_cco(): void
    {
        $contract = $this->makeContract($this->makeCustomer());
        $order = app(ContractChangeOrderService::class)->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Tambah pekerjaan ME lantai 9',
            'value_change' => 150_000_000,
            'reason' => 'permintaan_pelanggan',
        ]);
        $order->submit($this->financeUser());

        $crmApprover = $this->approver('crm-approver@test.local', ['crm.view', 'crm.approve']);
        [, $token] = $this->issue('crm/contract-change-orders', $order->id, [], $crmApprover);

        $this->post("/persetujuan/{$token}", ['decision' => 'approved'])->assertOk();

        // Mode record: evidence only. The CCO stays submitted, and the internal
        // proxy path (keputusan #6) is still the hand that moves it.
        $this->assertSame('submitted', $order->refresh()->status->value);

        app(ContractChangeOrderService::class)->approve($order, $this->financeApprover());
        $this->assertSame('approved', $order->refresh()->status->value);
    }

    // ------------------------------------------------------------- transisi

    public function test_an_external_approval_transitions_the_work_permit_through_the_adapter(): void
    {
        $issuer = $this->approver();
        $permit = $this->submittedPermit($this->project(), $this->maker());
        [, $token] = $this->issue('projects/work-permits', $permit->id, [], $issuer);

        $this->post("/persetujuan/{$token}", ['decision' => 'approved'])->assertOk();

        $permit->refresh();
        $this->assertSame('approved', $permit->status->value);

        $trail = $permit->approvals()->where('action', 'approved')->first();
        $this->assertNotNull($trail);
        $this->assertSame($issuer->id, $trail->user_id, 'the issuer stands proxy on the internal trail');
        $this->assertStringContainsString('Keputusan eksternal', (string) $trail->note);
    }

    public function test_an_external_rejection_rejects_the_work_permit(): void
    {
        $issuer = $this->approver();
        $permit = $this->submittedPermit($this->project(), $this->maker());
        [, $token] = $this->issue('projects/work-permits', $permit->id, [], $issuer);

        $this->post("/persetujuan/{$token}", ['decision' => 'rejected', 'notes' => 'JSA belum lengkap.'])->assertOk();

        $this->assertSame('rejected', $permit->refresh()->status->value);
        $this->assertSame(1, $permit->approvals()->where('action', 'rejected')->count());
    }

    public function test_the_adapter_refuses_a_link_that_would_self_approve(): void
    {
        $maker = $this->maker();
        $permit = $this->submittedPermit($this->project(), $maker);

        // The guard at issue() refuses this shape up front; the adapter must
        // hold even when the row exists anyway (resubmission drift): a link
        // whose ISSUER is the document's submitter may not approve it.
        $token = 'self-'.str_repeat('k', 36);
        $row = ExternalApproval::query()->create([
            'document_slug' => 'projects/work-permits',
            'document_id' => $permit->id,
            'party' => 'mk',
            'name' => 'Ir. Handoko Wibawa',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'issued_by' => $maker->id,
        ]);

        $page = $this->post("/persetujuan/{$token}", ['decision' => 'approved']);

        $this->assertSame('submitted', $permit->refresh()->status->value, 'maker-checker holds');
        $this->assertNull($row->refresh()->decided_at, 'the refused decision must roll back whole');
        $this->assertStringContainsString('pemisahan tugas', mb_strtolower($page->getContent()));
    }

    // ---------------------------------------------------------- notification

    public function test_approve_holders_are_notified_with_a_link_to_the_document(): void
    {
        $watcher = $this->approver('lonceng@test.local');
        $issuer = $this->approver('penerbit-2@test.local');
        $report = $this->report($this->project());
        [, $token] = $this->issue('projects/daily-reports', $report->id, [], $issuer);

        $this->post("/persetujuan/{$token}", ['decision' => 'approved'])->assertOk();

        $notification = Notification::query()
            ->where('user_id', $watcher->id)
            ->where('title', 'like', 'Keputusan eksternal tercatat%')
            ->first();

        $this->assertNotNull($notification, 'prj.approve holders must hear about the decision');
        $this->assertSame('#/d/projects/daily-reports/'.$report->id, $notification->link);
        $this->assertStringContainsString($report->code, $notification->title);
    }

    // --------------------------------------------------------------- routes

    public function test_both_public_routes_are_throttled_like_the_login_route(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $route = collect(Route::getRoutes())->first(
                fn ($r) => $r->uri() === 'persetujuan/{token}' && in_array($method, $r->methods(), true),
            );

            $this->assertNotNull($route, "route {$method} persetujuan/{token} must exist");
            $this->assertContains('throttle:10,1', $route->middleware());
        }
    }

    /** "Setuju dengan catatan" tanpa catatan bukan keputusan yang utuh. */
    public function test_setuju_dengan_catatan_tanpa_catatan_ditolak(): void
    {
        $report = $this->report($this->project());
        [$row, $token] = $this->issue('projects/daily-reports', $report->id);

        $response = $this->post("/persetujuan/{$token}", [
            'decision' => 'approved_with_notes',
            'notes' => '',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('harus menyertakan catatannya', $response->getContent());
        $this->assertNull($row->fresh()->decision);
    }

    /**
     * Halaman terminal tidak membawa nama penerima — tautan mati bisa dibuka
     * siapa pun yang memegangnya, dan janjinya hanya label + kode + alasan.
     */
    public function test_halaman_terminal_tidak_membocorkan_nama_penerima(): void
    {
        $issuer = $this->approver();
        $report = $this->report($this->project());
        [$row, $token] = $this->issue('projects/daily-reports', $report->id, [], $issuer);

        $this->service->revoke($row, $issuer);

        $response = $this->get("/persetujuan/{$token}");

        $response->assertStatus(410);
        $this->assertStringNotContainsString($row->name, $response->getContent());
        $this->assertStringContainsString('dicabut', $response->getContent());
    }
}
