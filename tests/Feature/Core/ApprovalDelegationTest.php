<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Estimation\Models\Boq;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * DELEGASI "a.n." — apa yang diberikannya, dan tiga hal yang tidak (F-1 / T1.5).
 *
 * Tanpa delegasi yang tercatat, cuti seorang direktur berakhir di salah satu
 * dari dua tempat: antrean yang berhenti (diukur 4 Sep 2026 —
 * PAY/2026/VIII/0002 menunggu 33 hari) atau kata sandi yang dipinjamkan, yang
 * mengubah seluruh jejak persetujuan aplikasi ini menjadi fiksi.
 *
 * Yang diuji di sini adalah batas-batasnya, karena Gate::before berjalan
 * MENDAHULUI setiap policy dan setiap middleware permission: sebuah kebocoran
 * di sana adalah kebocoran di mana-mana.
 */
class ApprovalDelegationTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userHolding(string $email, string ...$permissions): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas '.$email,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function delegate(User $giver, User $delegate, ?string $scope = null, array $attributes = []): ApprovalDelegation
    {
        $row = ApprovalDelegation::query()->create(array_merge([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => $scope,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
            'reason' => 'Cuti tahunan',
        ], $attributes));

        ApprovalDelegations::flushMemo();

        return $row;
    }

    private function submittedBoq(float $amount, User $maker): Boq
    {
        /** @var Boq $boq */
        $boq = Boq::query()->create([
            'title' => 'RAB Struktur',
            'total' => $amount,
            'status' => DocumentStatus::Draft,
        ]);

        return $boq->submit($maker);
    }

    // ------------------------------------------------- apa yang DIBERIKAN

    public function test_a_delegate_may_use_the_approve_right_the_giver_holds(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve');
        $delegate = $this->userHolding('wakil@t.local', 'est.view');

        $this->assertFalse($delegate->can('est.approve'), 'before the delegation');

        $this->delegate($giver, $delegate);

        $this->assertTrue($delegate->fresh()->can('est.approve'));
    }

    public function test_the_delegate_actually_approves_through_the_module_endpoint(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve');
        $delegate = $this->userHolding('wakil@t.local', 'est.view');
        $maker = $this->userHolding('drafter@t.local', 'est.create');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq(50_000_000, $maker);

        $this->actingAs($delegate)->postJson("/api/estimation/boqs/{$boq->id}/approve")->assertOk();

        $this->assertSame(DocumentStatus::Approved, $boq->fresh()->status);
    }

    /** Jejak dan setiap tampilan membacanya sebagai "Budi a.n. Sari". */
    public function test_the_trail_records_who_the_delegate_acted_for(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.view');
        $maker = $this->userHolding('drafter@t.local', 'est.create');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq(50_000_000, $maker);

        $this->actingAs($delegate)->postJson("/api/estimation/boqs/{$boq->id}/approve")->assertOk();

        $row = $boq->approvals()->where('action', 'approved')->latest('id')->first();

        $this->assertSame($delegate->id, (int) $row->user_id);
        $this->assertSame($giver->id, (int) $row->on_behalf_of_user_id);

        $trail = $this->actingAs($this->userHolding('pembaca@t.local', 'est.view'))
            ->getJson("/api/estimation/boqs/{$boq->id}")
            ->assertOk()
            ->json('data.approvals');

        $approved = collect($trail)->firstWhere('action', 'approved');
        $this->assertSame($delegate->name, $approved['user']['name']);
        $this->assertSame($giver->name, $approved['on_behalf_of']['name']);
    }

    /** Sebuah persetujuan oleh orang yang memegang haknya SENDIRI bukan "a.n." siapa pun. */
    public function test_an_approver_with_the_right_of_their_own_is_not_recorded_as_acting_for_anyone(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.approve', 'est.view');
        $maker = $this->userHolding('drafter@t.local', 'est.create');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq(50_000_000, $maker);

        $this->actingAs($delegate)->postJson("/api/estimation/boqs/{$boq->id}/approve")->assertOk();

        $row = $boq->approvals()->where('action', 'approved')->latest('id')->first();
        $this->assertNull($row->on_behalf_of_user_id);
    }

    // --------------------------------------------- apa yang TIDAK diberikan

    /**
     * SATU-SATUNYA ability yang pernah datang dari delegasi adalah persetujuan.
     * Gate::before mendahului setiap policy di aplikasi ini; kalau baris ini
     * merah, seluruh model izin aplikasi ini bocor.
     */
    public function test_a_delegation_never_grants_anything_but_an_approve_ability(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve', 'est.approve-director',
            'fin.post', 'iam.update', 'est.delete', 'est.create', 'core.update');
        $delegate = $this->userHolding('wakil@t.local', 'est.view');

        $this->delegate($giver, $delegate);
        $delegate = $delegate->fresh();

        $this->assertTrue($delegate->can('est.approve'));
        $this->assertTrue($delegate->can('est.approve-director'));

        foreach (['fin.post', 'iam.update', 'est.delete', 'est.create', 'core.update', 'est.update'] as $ability) {
            $this->assertFalse($delegate->can($ability), "delegation must never grant {$ability}");
        }
    }

    /** Lingkup adalah lingkup: delegasi prc tidak menyentuh est. */
    public function test_a_scoped_delegation_does_not_reach_another_module(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve', 'prc.approve');
        $delegate = $this->userHolding('wakil@t.local', 'est.view');

        $this->delegate($giver, $delegate, 'prc');
        $delegate = $delegate->fresh();

        $this->assertTrue($delegate->can('prc.approve'));
        $this->assertFalse($delegate->can('est.approve'));
    }

    /** Delegasi tidak pernah membuat hak yang pemberinya sendiri tidak punya. */
    public function test_a_delegation_cannot_lend_a_right_the_giver_does_not_hold(): void
    {
        $giver = $this->userHolding('kabag@t.local', 'est.approve');
        $delegate = $this->userHolding('wakil@t.local', 'est.view');

        $this->delegate($giver, $delegate);

        $this->assertTrue($delegate->fresh()->can('est.approve'));
        $this->assertFalse($delegate->fresh()->can('est.approve-director'));
    }

    /** Tidak berantai: A→B dan B→C tidak memberikan hak A kepada C. */
    public function test_a_delegation_does_not_chain(): void
    {
        $a = $this->userHolding('a@t.local', 'est.approve');
        $b = $this->userHolding('b@t.local', 'est.view');
        $c = $this->userHolding('c@t.local', 'est.view');

        $this->delegate($a, $b);
        $this->delegate($b, $c);

        $this->assertTrue($b->fresh()->can('est.approve'));
        $this->assertFalse($c->fresh()->can('est.approve'), 'a chain would hand A\'s right to somebody A never chose');
    }

    public function test_a_delegation_outside_its_window_grants_nothing(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve');
        $future = $this->userHolding('besok@t.local', 'est.view');
        $past = $this->userHolding('kemarin@t.local', 'est.view');

        $this->delegate($giver, $future, null, [
            'starts_at' => now()->addDays(3)->toDateString(),
            'ends_at' => now()->addDays(10)->toDateString(),
        ]);
        $this->delegate($giver, $past, null, [
            'starts_at' => now()->subDays(10)->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($future->fresh()->can('est.approve'));
        $this->assertFalse($past->fresh()->can('est.approve'));
    }

    public function test_a_revoked_delegation_grants_nothing(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve');
        $delegate = $this->userHolding('wakil@t.local', 'est.view');

        $row = $this->delegate($giver, $delegate);
        $this->assertTrue($delegate->fresh()->can('est.approve'));

        $row->forceFill(['revoked_at' => now()])->save();
        ApprovalDelegations::flushMemo();

        $this->assertFalse($delegate->fresh()->can('est.approve'));
    }

    public function test_a_delegation_from_a_deactivated_giver_grants_nothing(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve');
        $delegate = $this->userHolding('wakil@t.local', 'est.view');

        $this->delegate($giver, $delegate);
        $giver->forceFill(['is_active' => false])->save();

        $this->assertFalse($delegate->fresh()->can('est.approve'));
    }

    // ------------------------------------------------ maker-checker + delegasi

    /** Yang diajukan DIRINYA SENDIRI: penolakan maker-checker yang lama. */
    public function test_a_delegate_may_not_approve_what_they_submitted_themselves(): void
    {
        $giver = $this->userHolding('direktur@t.local', 'est.approve');
        $delegate = $this->userHolding('wakil@t.local', 'est.view', 'est.create');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq(50_000_000, $delegate);

        $this->expectException(SelfApprovalException::class);
        $boq->approve($delegate->fresh());
    }

    /**
     * Yang diajukan PEMBERI DELEGASINYA: penolakan baru, dan yang paling
     * penting dari keduanya. Sebuah proxy yang menyetujui pekerjaan orang yang
     * diwakilinya adalah persetujuan-sendiri yang memakai topi — hak yang
     * dipakai adalah hak si pengaju.
     */
    public function test_a_delegate_may_not_approve_what_the_giver_submitted(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve', 'est.create');
        $delegate = $this->userHolding('budi@t.local', 'est.view');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq(50_000_000, $giver);

        $response = $this->actingAs($delegate)
            ->postJson("/api/estimation/boqs/{$boq->id}/approve")
            ->assertStatus(422);

        $message = (string) $response->json('message');
        $this->assertStringContainsString($giver->name, $message);
        $this->assertStringContainsString('delegasi', $message);

        $this->assertSame(DocumentStatus::Submitted, $boq->fresh()->status);
    }

    /** Dokumen orang KETIGA tetap boleh — delegasi dibuat untuk itu. */
    public function test_a_delegate_may_still_approve_a_third_party_submission(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.view');
        $maker = $this->userHolding('drafter@t.local', 'est.create');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq(50_000_000, $maker);

        $this->actingAs($delegate)->postJson("/api/estimation/boqs/{$boq->id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Approved, $boq->fresh()->status);
    }

    // ----------------------------------------------------------- notifikasi

    /** Delegasi tanpa pemberitahuan adalah setengah fitur: antrean tetap berhenti. */
    public function test_the_delegate_is_told_a_document_is_waiting(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.view');
        $maker = $this->userHolding('drafter@t.local', 'est.create');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq(50_000_000, $maker);

        $recipients = DB::table('core_notifications')
            ->where('event', 'document.submitted')
            ->pluck('user_id')
            ->all();

        $this->assertContains($giver->id, $recipients, 'the permission holder is told, as before');
        $this->assertContains($delegate->id, $recipients, 'and so is the delegate holding the right today');
        $this->assertNotContains($maker->id, $recipients, 'the submitter is never told about their own click');
        $this->assertNotNull($boq->fresh());
    }

    // ------------------------------------------------------------- endpoint

    public function test_a_user_may_only_delegate_their_own_right(): void
    {
        $actor = $this->userHolding('sari@t.local', 'est.approve');
        $other = $this->userHolding('lain@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.view');

        $this->actingAs($actor)->postJson('/api/core/approval-delegations', [
            'giver_user_id' => $other->id,
            'delegate_user_id' => $delegate->id,
            'starts_at' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('giver_user_id');

        $this->actingAs($actor)->postJson('/api/core/approval-delegations', [
            'delegate_user_id' => $delegate->id,
            'starts_at' => now()->toDateString(),
        ])->assertStatus(201);
    }

    public function test_an_iam_admin_may_delegate_on_behalf_of_somebody_else(): void
    {
        $admin = $this->userHolding('admin@t.local', 'iam.update');
        $giver = $this->userHolding('sari@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.view');

        $this->actingAs($admin)->postJson('/api/core/approval-delegations', [
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'starts_at' => now()->toDateString(),
        ])->assertStatus(201);

        $this->assertTrue($delegate->fresh()->can('est.approve'));
    }

    /** Barisnya tetap ada sesudah dicabut: ia menjelaskan setiap "a.n." yang ditinggalkannya. */
    public function test_revoking_keeps_the_row(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve');
        $delegate = $this->userHolding('budi@t.local', 'est.view');
        $row = $this->delegate($giver, $delegate);

        $this->actingAs($giver)->deleteJson("/api/core/approval-delegations/{$row->id}")->assertOk();

        $this->assertDatabaseHas('core_approval_delegations', ['id' => $row->id]);
        $this->assertNotNull($row->fresh()->revoked_at);
        $this->assertFalse($delegate->fresh()->can('est.approve'));
    }

    /**
     * Sebuah delegasi yang tidak memberikan apa pun disimpan, TETAPI
     * dikatakan — diam di sini berarti seseorang pergi cuti mengira antreannya
     * tertangani.
     */
    public function test_a_delegation_from_a_giver_with_no_approve_right_says_so(): void
    {
        $giver = $this->userHolding('staf@t.local', 'est.view');
        $delegate = $this->userHolding('budi@t.local', 'est.view');

        $message = (string) $this->actingAs($giver)->postJson('/api/core/approval-delegations', [
            'delegate_user_id' => $delegate->id,
            'starts_at' => now()->toDateString(),
        ])->assertStatus(201)->json('message');

        $this->assertStringContainsString('belum memberikan apa pun', $message);
    }

    public function test_the_giver_and_the_delegate_may_not_be_the_same_person(): void
    {
        $user = $this->userHolding('sari@t.local', 'est.approve');

        $this->actingAs($user)->postJson('/api/core/approval-delegations', [
            'delegate_user_id' => $user->id,
            'starts_at' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('delegate_user_id');
    }

    public function test_the_index_only_ever_shows_rows_that_concern_the_caller(): void
    {
        $sari = $this->userHolding('sari@t.local', 'est.approve');
        $budi = $this->userHolding('budi@t.local', 'est.view');
        $orangLain = $this->userHolding('lain@t.local', 'est.approve');
        $keempat = $this->userHolding('keempat@t.local', 'est.view');

        $mine = $this->delegate($sari, $budi);
        $theirs = $this->delegate($orangLain, $keempat);

        $ids = collect($this->actingAs($budi)->getJson('/api/core/approval-delegations')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }
}
