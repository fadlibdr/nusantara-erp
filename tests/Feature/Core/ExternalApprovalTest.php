<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Attachment;
use Modules\Core\Models\ExternalApproval;
use Modules\Crm\Models\ContractChangeOrder;
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
 * P0-F prerequisite — persetujuan eksternal (MK/Owner), sisi TERBIT/CATAT.
 *
 * Keputusan pemilik #1 (✅ 22 Agu): MK/Owner memutuskan lewat TAUTAN
 * SEKALI-PAKAI (setuju / setuju dengan catatan / tolak) atau LEMBAR FISIK.
 * Yang dijaga file ini adalah pintu internalnya:
 *
 *  - menerbitkan tautan adalah kuasa setingkat menyetujui — izin
 *    {prefix}.approve modul PEMILIK dokumen, dibaca dari registri;
 *  - token polos tampil TEPAT SEKALI di respons penerbitan; basis data hanya
 *    menyimpan sha256-nya, dan daftar tautan tidak pernah membawa hash;
 *  - tautan CCO hanya terbit saat submitted (keputusan #7: pertahankan);
 *  - mode transisi (izin kerja): pengaju dokumen tidak boleh menerbitkan
 *    tautan untuk dokumennya sendiri — keputusan dari tautan diterapkan atas
 *    nama penerbit, dan maker-checker tidak boleh dilewati;
 *  - lembar fisik hanya tercatat bila lampirannya milik dokumen YANG SAMA.
 */
class ExternalApprovalTest extends ErpTestCase
{
    use FinanceFixtures;

    private ?User $admin = null;

    // -------------------------------------------------------------- fixtures

    private function admin(): User
    {
        if ($this->admin === null) {
            $this->admin = $this->adminUser();
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    private function userWith(array $permissions, string $email): User
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::findOrCreate('r-'.md5($email), 'web');
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengguna '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-091',
            'name' => 'Gedung Arsip Nasional',
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
        $employee = Employee::query()->firstOrCreate(['code' => 'EMP-7101'], [
            'name' => 'Sutrisno Hadi',
            'nik_ktp' => '3216012504780002',
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
            'ppe_required' => ['Helm proyek', 'Full body harness'],
            'valid_from' => '2026-06-15 08:00',
            'valid_until' => '2026-06-15 17:00',
            'requested_by' => $employee->id,
            'status' => DocumentStatus::Draft,
        ]);

        return $permit->submit($maker);
    }

    private function submittedCco(User $maker): ContractChangeOrder
    {
        $contract = $this->makeContract($this->makeCustomer());

        $order = app(ContractChangeOrderService::class)->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Tambah pekerjaan ME lantai 9',
            'value_change' => 150_000_000,
            'reason' => 'permintaan_pelanggan',
        ]);

        return $order->submit($maker);
    }

    private function issuePayload(string $slug, int $id, array $overrides = []): array
    {
        return array_merge([
            'document_type' => $slug,
            'document_id' => $id,
            'party' => 'mk',
            'name' => 'Ir. Handoko Wibawa',
            'organization' => 'PT MK Nusantara Konsultan',
            'email' => 'handoko@mk-nusantara.co.id',
        ], $overrides);
    }

    // ------------------------------------------------------------- issuance

    public function test_issuing_a_link_requires_the_owning_modules_approve_permission(): void
    {
        $report = $this->report($this->project());

        Sanctum::actingAs($this->userWith(['prj.view', 'prj.update'], 'pengawas@test.local'));
        $denied = $this->postJson('/api/core/external-approvals', $this->issuePayload('projects/daily-reports', $report->id));
        $denied->assertStatus(403);
        $this->assertStringContainsString('prj.approve', (string) $denied->json('message'));

        Sanctum::actingAs($this->userWith(['prj.view', 'prj.approve'], 'manajer@test.local'));
        $this->postJson('/api/core/external-approvals', $this->issuePayload('projects/daily-reports', $report->id))
            ->assertCreated();
    }

    public function test_the_plaintext_token_is_shown_exactly_once_and_only_its_hash_is_stored(): void
    {
        $this->admin();
        $report = $this->report($this->project());

        $response = $this->postJson('/api/core/external-approvals', $this->issuePayload('projects/daily-reports', $report->id))
            ->assertCreated();

        $url = (string) $response->json('data.url');
        $this->assertStringContainsString('/persetujuan/', $url);
        $token = substr($url, strrpos($url, '/') + 1);
        $this->assertNotSame('', $token);

        $row = ExternalApproval::query()->findOrFail((int) $response->json('data.approval.id'));
        $this->assertSame(hash('sha256', $token), $row->token_hash, 'DB carries the sha256, nothing else');
        $this->assertStringNotContainsString($token, json_encode($row->getAttributes()), 'plaintext never stored');

        // The issuance response itself must not leak the hash either, and the
        // listing afterwards carries neither hash nor plaintext.
        $this->assertNull($response->json('data.approval.token_hash'));

        $list = $this->getJson('/api/core/external-approvals?document_type=projects/daily-reports&document_id='.$report->id)
            ->assertOk();
        $this->assertNull($list->json('data.0.token_hash'));
        $this->assertStringNotContainsString($token, $list->getContent());
    }

    public function test_a_cco_link_may_only_be_issued_while_the_cco_is_submitted(): void
    {
        $admin = $this->admin();
        $contract = $this->makeContract($this->makeCustomer());

        $draft = app(ContractChangeOrderService::class)->create([
            'contract_id' => $contract->id,
            'change_date' => '2026-06-01',
            'title' => 'Tambah pekerjaan ME lantai 9',
            'value_change' => 150_000_000,
            'reason' => 'permintaan_pelanggan',
        ]);

        $refused = $this->postJson('/api/core/external-approvals', $this->issuePayload('crm/contract-change-orders', $draft->id));
        $refused->assertStatus(422);
        $this->assertStringContainsString('submitted', json_encode($refused->json()));

        $draft->refresh()->submit($this->financeUser());

        $this->postJson('/api/core/external-approvals', $this->issuePayload('crm/contract-change-orders', $draft->id))
            ->assertCreated();
    }

    public function test_a_transisi_link_requires_the_document_to_be_submitted(): void
    {
        $this->admin();
        $project = $this->project();

        $employee = Employee::query()->firstOrCreate(['code' => 'EMP-7103'], [
            'name' => 'Sutrisno Hadi',
            'nik_ktp' => '3216012504780004',
            'gender' => 'male',
            'birth_date' => '1978-04-25',
            'ptkp_status' => 'K/2',
            'join_date' => '2021-01-04',
            'employment_type' => 'tetap',
            'position' => 'Mandor Sipil',
            'department' => 'proyek',
            'base_salary' => 7_500_000,
        ]);

        $draft = WorkPermit::query()->create([
            'project_id' => $project->id,
            'permit_date' => '2026-06-15',
            'shift' => 'pagi',
            'work_description' => 'Galian pondasi zona C',
            'valid_from' => '2026-06-15 08:00',
            'valid_until' => '2026-06-15 17:00',
            'requested_by' => $employee->id,
            'status' => DocumentStatus::Draft,
        ]);

        $refused = $this->postJson('/api/core/external-approvals', $this->issuePayload('projects/work-permits', $draft->id));
        $refused->assertStatus(422);
        $this->assertStringContainsString('submitted', json_encode($refused->json()));
    }

    public function test_the_submitter_cannot_issue_the_transisi_link_for_their_own_document(): void
    {
        $admin = $this->admin();
        $permit = $this->submittedPermit($this->project(), $admin);

        $refused = $this->postJson('/api/core/external-approvals', $this->issuePayload('projects/work-permits', $permit->id));

        $refused->assertStatus(422);
        $this->assertStringContainsString('pengaju', json_encode($refused->json()));

        // A different approve-holder may issue it.
        Sanctum::actingAs($this->userWith(['prj.view', 'prj.approve'], 'direktur@test.local'));
        $this->postJson('/api/core/external-approvals', $this->issuePayload('projects/work-permits', $permit->id))
            ->assertCreated();
    }

    public function test_an_unknown_document_slug_is_refused(): void
    {
        $this->admin();

        $this->postJson('/api/core/external-approvals', $this->issuePayload('finance/ap-bills', 1))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------- revoke

    public function test_revoking_stamps_the_link_and_a_decided_link_cannot_be_revoked(): void
    {
        $this->admin();
        $report = $this->report($this->project());

        $issued = $this->postJson('/api/core/external-approvals', $this->issuePayload('projects/daily-reports', $report->id))
            ->assertCreated();
        $id = (int) $issued->json('data.approval.id');

        $this->postJson("/api/core/external-approvals/{$id}/revoke")->assertOk();
        $this->assertNotNull(ExternalApproval::query()->findOrFail($id)->revoked_at);

        // A second revoke is refused — the stamp is evidence of one act.
        $this->postJson("/api/core/external-approvals/{$id}/revoke")->assertStatus(422);

        // A decided row refuses revocation: the decision already happened.
        $decided = ExternalApproval::query()->create([
            'document_slug' => 'projects/daily-reports',
            'document_id' => $report->id,
            'party' => 'owner',
            'name' => 'Dr. Ratna Sari',
            'token_hash' => hash('sha256', 'x-'.str_repeat('a', 38)),
            'expires_at' => now()->addDay(),
            'decision' => 'approved',
            'decided_at' => now(),
            'decided_via' => 'link',
        ]);

        $refused = $this->postJson("/api/core/external-approvals/{$decided->id}/revoke");
        $refused->assertStatus(422);
        $this->assertStringContainsString('keputusan', mb_strtolower(json_encode($refused->json())));
    }

    // ------------------------------------------------------------- physical

    public function test_recording_a_physical_sheet_demands_the_attachment_belong_to_the_same_document(): void
    {
        $this->admin();
        $project = $this->project();
        $report = $this->report($project);
        $other = $this->report($project, '2026-06-11');

        $foreign = Attachment::query()->create([
            'attachable_type' => DailyReport::class,
            'attachable_id' => $other->id,
            'disk' => 'local',
            'path' => 'attachments/1/foreign.pdf',
            'original_name' => 'lembar-ttd.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1000,
            'sha256' => str_repeat('a', 64),
        ]);

        $payload = [
            'document_type' => 'projects/daily-reports',
            'document_id' => $report->id,
            'party' => 'mk',
            'name' => 'Ir. Handoko Wibawa',
            'organization' => 'PT MK Nusantara Konsultan',
            'decision' => 'approved_with_notes',
            'decision_notes' => 'Perbaiki catatan cuaca sore.',
            'decided_at' => '2026-06-12',
            'attachment_id' => $foreign->id,
        ];

        $refused = $this->postJson('/api/core/external-approvals/record-physical', $payload);
        $refused->assertStatus(422);
        $this->assertStringContainsString('dokumen lain', json_encode($refused->json()));

        $own = Attachment::query()->create([
            'attachable_type' => DailyReport::class,
            'attachable_id' => $report->id,
            'disk' => 'local',
            'path' => 'attachments/1/own.pdf',
            'original_name' => 'lembar-ttd.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1000,
            'sha256' => str_repeat('b', 64),
        ]);

        $ok = $this->postJson('/api/core/external-approvals/record-physical', array_merge($payload, ['attachment_id' => $own->id]))
            ->assertCreated();

        $row = ExternalApproval::query()->findOrFail((int) $ok->json('data.approval.id'));
        $this->assertSame('physical', $row->decided_via);
        $this->assertSame('approved_with_notes', $row->decision?->value);
        $this->assertNull($row->token_hash, 'a physical sheet has no token');

        // Evidence recorded = the same first-decision lock as the link path.
        $this->assertNotNull($report->refresh()->locked_at);
    }

    // -------------------------------------------------------------- listing

    public function test_listing_needs_the_owning_modules_view_permission(): void
    {
        $report = $this->report($this->project());

        Sanctum::actingAs($this->userWith(['fin.view'], 'kasir@test.local'));
        $this->getJson('/api/core/external-approvals?document_type=projects/daily-reports&document_id='.$report->id)
            ->assertStatus(403);

        Sanctum::actingAs($this->userWith(['prj.view'], 'pembaca@test.local'));
        $this->getJson('/api/core/external-approvals?document_type=projects/daily-reports&document_id='.$report->id)
            ->assertOk();
    }
}
