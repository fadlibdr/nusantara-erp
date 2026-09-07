<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Models\AuditLog;
use Modules\Core\Support\AuditedModels;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * MEMINJAMKAN HAK MENYETUJUI ADALAH PERUBAHAN IZIN, DAN IA DICATAT
 * (verifikasi F-1, 7 Sep 2026).
 *
 * F-1 mengaudit setiap perubahan `approvals.*` dari→ke — dua uji memakukannya —
 * jadi ATURAN uangnya tercatat. Pemberian hak untuk menerapkan aturan itu
 * tidak: ApprovalDelegation tidak ada di AuditedModels, dan sesudah satu siklus
 * penuh buat-delegasikan-setujui, core_audit_log hanya memuat baris User dari
 * fixture. Barisnya sendiri pun catatan separuh — ada `created_by`, tidak ada
 * `revoked_by`, sehingga "siapa mencabut delegasi ini" hanya dijawab sebuah
 * stempel waktu, padahal pencabutan oleh ORANG KETIGA adalah persis kejadian
 * yang ditanyakan sebuah penyelidikan.
 */
class ApprovalDelegationAuditTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userHolding(string $name, string $email, string ...$permissions): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @return Collection<int, AuditLog> */
    private function delegationLog()
    {
        return AuditLog::query()
            ->where('auditable_type', ApprovalDelegation::class)
            ->orderBy('id')
            ->get();
    }

    public function test_the_delegation_is_an_audited_model(): void
    {
        $this->assertTrue(AuditedModels::isAudited(ApprovalDelegation::class));
        $this->assertContains(ApprovalDelegation::class, AuditedModels::classes());
    }

    public function test_creating_a_delegation_is_recorded_with_both_names(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari@t.local', 'est.approve');
        $delegate = $this->userHolding('Budi Wakil', 'budi@t.local', 'est.view');

        $this->actingAs($giver)->postJson('/api/core/approval-delegations', [
            'delegate_user_id' => $delegate->id,
            'scope' => 'est',
            'starts_at' => now()->toDateString(),
        ])->assertStatus(201);

        $log = $this->delegationLog();

        $this->assertCount(1, $log);
        $this->assertSame('created', $log[0]->event);
        $this->assertSame('Sari Direktur → Budi Wakil (est)', $log[0]->auditable_label);
        $this->assertSame($giver->id, (int) $log[0]->user_id);
    }

    /**
     * DAN PENCABUTANNYA MENYEBUT SIAPA. Yang diuji bukan kasus mudah (pemberi
     * mencabut miliknya sendiri) melainkan yang sulit: seorang administrator
     * mencabut delegasi orang lain.
     */
    public function test_a_third_party_revocation_names_the_person_who_did_it(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari@t.local', 'est.approve');
        $delegate = $this->userHolding('Budi Wakil', 'budi@t.local', 'est.view');
        $admin = $this->userHolding('Admin Sistem', 'admin@t.local', 'iam.update');

        $row = ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
            'created_by' => $giver->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/core/approval-delegations/{$row->id}")->assertOk();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->revoked_at);
        $this->assertSame($admin->id, (int) $fresh->revoked_by);
        $this->assertSame('Admin Sistem', $fresh->revokedBy?->name);

        $updated = $this->delegationLog()->firstWhere('event', 'updated');

        $this->assertNotNull($updated, 'revoking a delegation with no audit line is a permission change nobody can find');
        $this->assertSame($admin->id, (int) $updated->user_id);
        $this->assertArrayHasKey('revoked_by', $updated->changes);
        $this->assertSame($admin->id, (int) $updated->changes['revoked_by']['to']);
    }
}
