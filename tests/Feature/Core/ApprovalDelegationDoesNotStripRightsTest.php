<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Estimation\Models\Boq;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * SEBUAH DELEGASI TIDAK MENCABUT HAK ORANG YANG MENERIMANYA
 * (verifikasi F-1, 7 Sep 2026).
 *
 * F-1 yang dikirim menolak setiap persetujuan atas pengajuan seorang pemberi
 * delegasi, TANPA menanyakan apakah penyetujunya membutuhkan delegasi itu.
 * Dua akibat, keduanya terukur:
 *
 *   PEMAKAIAN BIASA. Administrator Sistem menyerahkan haknya kepada direktur
 *   sebelum cuti — pemakaian paling wajar dari fitur ini — dan 2 dari 4 baris
 *   antrean direktur itu menjadi tidak dapat disetujui, hak yang dipegangnya
 *   sendiri sebelum delegasi itu ada. (Pada dataset demo 13 dari 14 pengajuan
 *   tercatat milik Administrator Sistem.)
 *
 *   PERACUNAN. Membuat delegasi tidak menuntut izin apa pun selama pembuatnya
 *   menyebut DIRINYA sebagai pemberi. Maka pengguna tanpa satu izin pun
 *   melumpuhkan seorang direktur: POST → 201, dan setiap dokumen yang
 *   diajukannya menjadi 422 di tangan direktur itu. Penerimanya juga tidak
 *   dapat mencabutnya.
 *
 * Yang tersisa sesudah penyempitan adalah aturan aslinya, utuh, dan tiga uji
 * terakhir di bawah memakukannya.
 */
class ApprovalDelegationDoesNotStripRightsTest extends ErpTestCase
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

    private function delegate(User $giver, User $delegate, ?string $scope = null): ApprovalDelegation
    {
        $row = ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => $scope,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
            'reason' => 'Cuti tahunan',
        ]);

        ApprovalDelegations::flushMemo();

        return $row;
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

    // ------------------------------------------------------ tidak mencabut

    /** Pemakaian paling biasa: pengaju menyerahkan haknya kepada penyetujunya. */
    public function test_a_delegation_from_the_submitter_does_not_stop_a_native_approver(): void
    {
        $admin = $this->userHolding('admin@t.local', 'est.approve', 'est.create', 'est.view');
        $director = $this->userHolding('direktur@t.local', 'est.approve', 'est.view');

        $boq = $this->submittedBoq($admin);
        $this->delegate($admin, $director);

        $this->actingAs($director)->postJson("/api/estimation/boqs/{$boq->id}/approve")->assertOk();

        $this->assertSame(DocumentStatus::Approved, $boq->fresh()->status);
    }

    /** PERACUNAN: pembuat delegasi tanpa izin tidak melumpuhkan siapa pun. */
    public function test_a_delegation_from_someone_holding_nothing_poisons_nobody(): void
    {
        $attacker = $this->userHolding('tukang@t.local', 'est.create', 'est.view');
        $director = $this->userHolding('direktur@t.local', 'est.approve', 'est.view');

        $boq = $this->submittedBoq($attacker);

        // Jalur nyatanya: pengguna itu sendiri yang memPOST barisnya.
        $this->actingAs($attacker)->postJson('/api/core/approval-delegations', [
            'delegate_user_id' => $director->id,
            'starts_at' => now()->subDay()->toDateString(),
        ])->assertStatus(201);

        ApprovalDelegations::flushMemo();

        $this->actingAs($director)->postJson("/api/estimation/boqs/{$boq->id}/approve")->assertOk();

        $this->assertSame(DocumentStatus::Approved, $boq->fresh()->status);
    }

    /** Dan orang yang dibebani sebuah delegasi dapat mengembalikannya sendiri. */
    public function test_the_delegate_may_revoke_a_delegation_they_hold(): void
    {
        $attacker = $this->userHolding('tukang@t.local', 'est.create', 'est.view');
        $director = $this->userHolding('direktur@t.local', 'est.approve', 'est.view');

        $row = $this->delegate($attacker, $director);

        $held = collect($this->actingAs($director)->getJson('/api/core/approval-delegations')->assertOk()->json('data'))
            ->firstWhere('id', $row->id);
        $this->assertTrue($held['can_revoke'], 'a delegation you did not ask for must be one you can hand back');

        $this->actingAs($director)->deleteJson("/api/core/approval-delegations/{$row->id}")->assertOk();

        $this->assertNotNull($row->fresh()->revoked_at);
        $this->assertFalse($row->fresh()->isActive());
    }

    // ------------------------------------------------- dan tetap menolak

    /** Aturan aslinya, utuh: hak yang dipinjam tidak menyetujui pekerjaan pemiliknya. */
    public function test_a_delegate_without_the_right_of_their_own_is_still_refused(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve', 'est.create');
        $delegate = $this->userHolding('budi@t.local', 'est.view');

        $this->delegate($giver, $delegate);
        $boq = $this->submittedBoq($giver);

        $message = (string) $this->actingAs($delegate)
            ->postJson("/api/estimation/boqs/{$boq->id}/approve")
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString($giver->name, $message);
        $this->assertStringContainsString('delegasi', $message);
        $this->assertSame(DocumentStatus::Submitted, $boq->fresh()->status);
    }

    /**
     * DAN HAK DIREKTUR YANG DIPINJAM JUGA TIDAK. Penyetujunya memegang
     * est.approve sendiri, tetapi dokumen ini menuntut est.approve-director
     * dan satu-satunya sumber hak itu baginya adalah delegasi si pengaju.
     */
    public function test_a_borrowed_director_right_does_not_approve_the_givers_own_document(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve', 'est.approve-director', 'est.create');
        $delegate = $this->userHolding('budi@t.local', 'est.approve', 'est.view');

        $this->delegate($giver, $delegate);

        $boq = $this->submittedBoq($giver);
        // Stempel yang menuntut direktur, bentuk yang ditulis pengamat
        // Approval::creating ketika pemilik memasang ambang pada baris ini.
        $boq->approvals()->where('action', 'submitted')->latest('id')->first()
            ->forceFill(['policy' => [
                'v' => 1, 'type' => 'boq', 'prefix' => 'est', 'amount' => 50000000.0,
                'threshold' => 1.0, 'mode' => 'single_director', 'third_level_threshold' => null,
                'levels' => 1, 'director' => true,
            ]])->save();

        $this->actingAs($delegate)
            ->postJson("/api/estimation/boqs/{$boq->id}/approve")
            ->assertStatus(422);

        $this->assertSame(DocumentStatus::Submitted, $boq->fresh()->status);
    }
}
