<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\PurchaseRequisition;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * T2.11 — kartu "Menunggu persetujuan Anda" dan tautan Tugas Saya hanya bagi
 * pemegang izin `.approve` mana pun.
 *
 * Diukur 2 Sep 2026 (HASIL-UJI §1, S5 › cards): kartu tergambar untuk 11 dari
 * 11 peran demo, 8 di antaranya tidak menyetujui apa pun. Klien
 * menyembunyikannya lewat SATU predikat (schema.js ANY_APPROVE: ada izin yang
 * berakhiran `.approve`), dan predikat itu jujur hanya selama server memang
 * menyaring kotak masuk dengan bentuk yang sama. Gerak kartunya diukur harness
 * S1 (warehouse vs direktur); yang dipaku di sini adalah tiga hal yang bisa
 * hanyut diam-diam tanpa build step:
 *
 *  - GET core/inbox kosong bagi pemegang izin tanpa satu pun `.approve`,
 *    sementara dokumen yang sama tampil bagi pemegang `<awalan>.approve`;
 *  - `.approve-director` saja tidak membuka kotak masuk — itulah mengapa
 *    ANY_APPROVE sengaja tidak menghitungnya;
 *  - schema.js (tautan), dashboard.js (permintaan + kartu) dan api.js
 *    (session.can memanggil predikat fungsi) masih memakai predikat itu.
 */
class ApprovalInboxGateTest extends ErpTestCase
{
    public function test_the_inbox_is_empty_for_a_user_without_any_approve_permission(): void
    {
        $maker = $this->userWith('prc.create');
        // warehouse@nusantara.test's bundle (RoleSeeder): inv.* tanpa approve + prj.view.
        $storekeeper = $this->userWith('inv.view', 'inv.create', 'inv.update', 'inv.delete', 'prj.view');
        $approver = $this->userWith('prc.approve');
        $pr = $this->submittedPr($maker);

        $this->actingAs($storekeeper, 'sanctum')->getJson('/api/core/inbox')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);

        $this->actingAs($approver, 'sanctum')->getJson('/api/core/inbox')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', $pr->code);
    }

    public function test_a_director_signature_alone_does_not_open_the_inbox(): void
    {
        $maker = $this->userWith('prc.create');
        $director = $this->userWith('prc.approve-director');
        $this->submittedPr($maker);

        $this->actingAs($director, 'sanctum')->getJson('/api/core/inbox')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_the_spa_gates_the_link_the_request_and_the_card_with_one_predicate(): void
    {
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString(
            "export const ANY_APPROVE = (held) => held.some((one) => one.endsWith('.approve'));",
            $schema,
            'ANY_APPROVE harus mencerminkan penyaring ApprovalQueue::pending ("<awalan>.approve"), tanpa .approve-director.',
        );
        $this->assertStringContainsString("{ label: 'Tugas Saya', route: 'tugas', perm: ANY_APPROVE }", $schema);

        $dashboard = $this->spa('views/dashboard.js');
        $this->assertSame(
            2,
            substr_count($dashboard, 'session.can(ANY_APPROVE)'),
            'dashboard.js menggerbangi permintaan core/inbox DAN kartunya dengan predikat yang sama; satu saja berarti kartu kosong atau permintaan sia-sia kembali.',
        );

        $this->assertStringContainsString(
            "if (typeof permission === 'function') return permission(held);",
            $this->spa('api.js'),
            'session.can harus memanggil predikat fungsi dengan daftar izin; tanpa itu visibleNav menyembunyikan Tugas Saya dari semua orang.',
        );
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

    private function submittedPr(User $submitter): PurchaseRequisition
    {
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-12-31',
            'status' => 'draft',
            'purpose' => 'Uji gerbang kotak masuk',
            'requested_by' => $submitter->id,
        ]);
        $pr->submit($submitter);

        return $pr->fresh();
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }
}
