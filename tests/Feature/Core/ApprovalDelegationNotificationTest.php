<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Models\Notification;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Estimation\Models\Boq;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * "a.n." SAMPAI KE ORANG YANG MENUNGGU JAWABANNYA
 * (verifikasi F-1, 7 Sep 2026).
 *
 * F-1 mencap "a.n." pada core_approvals dan merendernya di layar detail, dan
 * pemberitahuan MENUNGGU sudah dikirim ke pemberi maupun delegatnya. Tetapi
 * pemberitahuan KEPUTUSAN — satu-satunya yang benar-benar sampai ke pengaju —
 * masih berbunyi "Petugas budi@t.local menyetujui boq / rab BOQ/2026/0001",
 * tanpa satu kata pun tentang Sari. Bagi pengaju, pemberitahuan itulah seluruh
 * ceritanya: ia tidak membuka layar detail untuk membaca jejak.
 */
class ApprovalDelegationNotificationTest extends ErpTestCase
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

    private function submittedBoq(User $maker): Boq
    {
        /** @var Boq $boq */
        $boq = Boq::query()->create([
            'title' => 'RAB Struktur',
            'total' => 50_000_000,
            'status' => DocumentStatus::Draft,
        ]);

        return $boq->submit($maker);
    }

    private function decisionBodyFor(User $submitter): string
    {
        return (string) Notification::query()
            ->where('user_id', $submitter->id)
            ->whereIn('event', [Notification::APPROVED, Notification::REJECTED])
            ->latest('id')
            ->value('body');
    }

    public function test_the_submitter_is_told_who_the_approver_acted_for(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari@t.local', 'est.approve');
        $delegate = $this->userHolding('Budi Wakil', 'budi@t.local', 'est.view');
        $maker = $this->userHolding('Dita Drafter', 'dita@t.local', 'est.create');

        ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
        ]);
        ApprovalDelegations::flushMemo();

        $boq = $this->submittedBoq($maker);

        $this->actingAs($delegate->fresh())
            ->postJson("/api/estimation/boqs/{$boq->id}/approve")
            ->assertOk();

        $this->assertStringContainsString('Budi Wakil a.n. Sari Direktur menyetujui', $this->decisionBodyFor($maker));
    }

    /** Penolakan juga — jejaknya sama, kalimatnya sama. */
    public function test_a_rejection_by_a_delegate_says_it_too(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari@t.local', 'est.approve');
        $delegate = $this->userHolding('Budi Wakil', 'budi@t.local', 'est.view');
        $maker = $this->userHolding('Dita Drafter', 'dita@t.local', 'est.create');

        ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
        ]);
        ApprovalDelegations::flushMemo();

        $boq = $this->submittedBoq($maker);

        $this->actingAs($delegate->fresh())
            ->postJson("/api/estimation/boqs/{$boq->id}/reject", ['note' => 'Volume galian salah'])
            ->assertOk();

        $this->assertStringContainsString('Budi Wakil a.n. Sari Direktur menolak', $this->decisionBodyFor($maker));
    }

    /**
     * DAN PERSETUJUAN BIASA TIDAK MENGARANG "a.n." — mencap seorang wakil pada
     * persetujuan yang tidak membutuhkan delegasi berarti menuliskan fiksi ke
     * dalam sesuatu yang dibaca orang.
     */
    public function test_an_approver_using_their_own_right_is_named_alone(): void
    {
        $approver = $this->userHolding('Ratna Manajer', 'ratna@t.local', 'est.approve');
        $maker = $this->userHolding('Dita Drafter', 'dita@t.local', 'est.create');

        $boq = $this->submittedBoq($maker);

        $this->actingAs($approver)->postJson("/api/estimation/boqs/{$boq->id}/approve")->assertOk();

        $body = $this->decisionBodyFor($maker);

        $this->assertStringContainsString('Ratna Manajer menyetujui', $body);
        $this->assertStringNotContainsString('a.n.', $body);
    }
}
