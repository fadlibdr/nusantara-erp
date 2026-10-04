<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseRequisition;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET core/my-work (ERRATA E-9, UX-F1) — Pekerjaan Saya: dokumen MILIK pemanggil yang masih
 * diajukan (`waiting`) atau ditolak (`needs_fix`), lewat registri dan fungsi kepemilikan yang sama
 * dengan kotak masuk (ApprovalQueue::ownerOf). Lima janji ERRATA dipaku di sini.
 */
class MyWorkTest extends ErpTestCase
{
    public function test_the_owner_sees_own_submitted_and_rejected_documents_in_the_right_buckets(): void
    {
        $maker = $this->userWith('prc.view', 'prc.create', 'prc.update');
        $approver = $this->userWith('prc.view', 'prc.approve');
        $waiting = $this->submittedPr($maker, 'Menunggu');
        $rejected = $this->submittedPr($maker, 'Ditolak');
        $rejected->reject($approver, 'Lampirkan penawaran pembanding.');

        $response = $this->actingAs($maker, 'sanctum')->getJson('/api/core/my-work')->assertOk();
        $response->assertJsonPath('meta.total', 2)->assertJsonPath('meta.failed', []);
        $rows = $response->json('data');

        // Perlu diperbaiki dulu, lalu yang menunggu.
        $this->assertSame([$rejected->code, $waiting->code], array_column($rows, 'code'));
        $this->assertSame(['needs_fix', 'waiting'], array_column($rows, 'bucket'));
        $this->assertSame(['rejected', 'submitted'], array_column($rows, 'status'));

        $fix = $rows[0];
        $this->assertSame('Lampirkan penawaran pembanding.', $fix['note']);
        $this->assertSame($approver->name, $fix['decided_by']);
        $this->assertNotNull($fix['decided_at']);
        $this->assertSame("#/d/procurement/purchase-requisitions/{$rejected->id}", $fix['link']);
        $this->assertSame('Ditolak', $fix['title']);
        foreach (['id', 'code', 'label', 'resource', 'title', 'amount', 'submitted_at', 'days_waiting', 'link', 'status', 'bucket'] as $key) {
            $this->assertArrayHasKey($key, $rows[1], "Baris waiting kehilangan {$key} (bentuk baris kotak masuk).");
        }
        $this->assertArrayNotHasKey('note', $rows[1]);
        $this->assertNotNull($response->json('meta.as_of'));
    }

    public function test_another_users_documents_are_not_offered(): void
    {
        $maker = $this->userWith('prc.view', 'prc.create');
        $other = $this->userWith('prc.view', 'prc.create');
        $this->submittedPr($other, 'Milik orang lain');

        $this->actingAs($maker, 'sanctum')->getJson('/api/core/my-work')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_a_document_without_a_submission_row_falls_back_to_the_owner_columns(): void
    {
        $maker = $this->userWith('prc.view', 'prc.create');
        // Dokumen yang berstatus submitted tanpa jejak pengajuan (dicetak mesin / impor): kolom
        // requested_by yang menentukan pemiliknya — fungsi yang sama dengan kotak masuk.
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'submitted',
            'purpose' => 'Tanpa baris pengajuan',
            'requested_by' => $maker->id,
        ]);
        $this->assertSame(0, DB::table('core_approvals')->where('approvable_id', $pr->id)->count());

        $this->actingAs($maker, 'sanctum')->getJson('/api/core/my-work')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', $pr->code)
            ->assertJsonPath('data.0.bucket', 'waiting');
    }

    public function test_a_type_that_throws_lands_in_meta_failed_and_the_rest_still_answers(): void
    {
        $maker = $this->userWith('prc.view', 'prc.create', 'crm.view');
        $pr = $this->submittedPr($maker, 'Masih dijawab');
        // Jenis yang gagal dimuat: tabelnya dibuang. MySQL menolak drop di balik kunci asing — matikan dulu (SQLite: PRAGMA yang sama).
        Schema::disableForeignKeyConstraints();
        Schema::drop('crm_quotations');
        Schema::enableForeignKeyConstraints();

        $response = $this->actingAs($maker, 'sanctum')->getJson('/api/core/my-work')->assertOk();
        $this->assertContains('Penawaran', $response->json('meta.failed'));
        $this->assertSame([$pr->code], array_column($response->json('data'), 'code'));
    }

    public function test_a_type_the_caller_cannot_view_contributes_no_rows(): void
    {
        // Pemilik tanpa prc.view (hak baca dicabut sesudah mengajukan): dokumennya tidak ditawarkan.
        $maker = $this->userWith('prc.view', 'prc.create');
        $pr = $this->submittedPr($maker, 'Hak baca dicabut');
        $maker->syncRoles([]);
        $blind = $this->userWith('inv.view');
        $maker->assignRole($blind->roles->first());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($maker->fresh(), 'sanctum')->getJson('/api/core/my-work')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.failed', []);
        $this->assertSame('submitted', $pr->fresh()->status->value);
    }

    public function test_the_inbox_and_my_work_share_one_ownership_function(): void
    {
        $queue = (string) file_get_contents(base_path('Modules/Core/Support/ApprovalQueue.php'));
        $work = (string) file_get_contents(base_path('Modules/Core/Support/MyWork.php'));
        $this->assertStringContainsString('public static function ownerOf(array $attrs, ?object $submission): ?int', $queue);
        $this->assertStringContainsString('$ownerId = self::ownerOf($attrs, $sub);', $queue, 'Kotak masuk tidak lagi memakai ownerOf().');
        $this->assertStringContainsString('ApprovalQueue::ownerOf($attrs, $sub)', $work);
        $this->assertStringContainsString('ApprovalQueue::ownedByEmployee($attrs, $employeeId)', $work);
        $this->assertStringNotContainsString('OWNER_KEYS', $work, 'MyWork menyalin daftar kolom pemilik — dua aturan kepemilikan.');
        $this->assertStringNotContainsString("use Modules\\Procurement", $work);
        $this->assertStringNotContainsString("use Modules\\Crm", $work);
    }

    private function userWith(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);
        $user = User::query()->create([
            'name' => 'Pengguna '.substr(md5(implode('|', $permissions).microtime()), 0, 4),
            'email' => substr(md5(implode('|', $permissions).microtime()), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function submittedPr(User $submitter, string $purpose): PurchaseRequisition
    {
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => $purpose,
            'requested_by' => $submitter->id,
        ]);
        $pr->submit($submitter);

        return $pr->fresh();
    }
}
