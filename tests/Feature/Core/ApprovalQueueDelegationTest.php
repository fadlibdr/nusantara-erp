<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Core\Support\ApprovalQueue;
use Modules\Estimation\Models\Boq;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * ANTREAN TIDAK MENAWARKAN PEKERJAAN YANG DIJAMIN DITOLAK
 * (verifikasi F-1, 7 Sep 2026).
 *
 * F-1 menambahkan penolakan yang benar (delegat tidak menyetujui pengajuan
 * pemberinya dengan hak pemberi itu) tetapi tidak mengajarkannya kepada
 * ApprovalQueue::pending. Akibatnya delegasi MEMBUAT baris-baris itu terlihat
 * — Gate::before meminjamkan <awalan>.approve — dan "Setujui massal" memberi
 * centang pada baris yang akan menjawab 422.
 *
 * Terukur pada salinan dataset demo: sesudah Administrator Sistem
 * mendelegasikan seluruh persetujuannya kepada login finance, antrean delegat
 * berisi 4 baris dan 2 di antaranya (PR/2026/III/0002, SPK/2026/III/0002)
 * dijamin gagal. Skenario S28 mencentang dua kotak pertama, yang kebetulan dua
 * yang berhasil, jadi 26 syaratnya hijau di atas antrean yang separuhnya tidak
 * dapat disetujui.
 */
class ApprovalQueueDelegationTest extends ErpTestCase
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

    private function delegate(User $giver, User $delegate): ApprovalDelegation
    {
        $row = ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
            'reason' => 'Cuti tahunan',
        ]);

        ApprovalDelegations::flushMemo();

        return $row;
    }

    private function submittedBoq(string $title, User $maker): Boq
    {
        /** @var Boq $boq */
        $boq = Boq::query()->create([
            'title' => $title,
            'total' => 50_000_000,
            'status' => DocumentStatus::Draft,
        ]);

        return $boq->submit($maker);
    }

    /**
     * SEPARUH ANTREAN YANG TIDAK DAPAT DISETUJUI TIDAK BOLEH TERGAMBAR. Delegat
     * di sini tidak memegang est.approve sendiri — ia melihat antrean itu HANYA
     * karena delegasinya — jadi pengajuan pemberinya tidak dapat ia putuskan.
     */
    public function test_the_queue_does_not_offer_the_givers_own_submissions_to_a_pure_delegate(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve', 'est.create');
        $delegate = $this->userHolding('budi@t.local', 'est.view');
        $someoneElse = $this->userHolding('drafter@t.local', 'est.create');

        $this->submittedBoq('RAB milik pemberi', $giver);
        $this->submittedBoq('RAB milik orang lain', $someoneElse);

        $this->delegate($giver, $delegate);

        $titles = array_column(ApprovalQueue::pending($delegate->fresh())['rows'], 'title');

        $this->assertSame(['RAB milik orang lain'], $titles);
    }

    /** Dan yang tergambar itu memang dapat disetujui — bukan hanya tidak ditolak. */
    public function test_what_is_left_can_actually_be_approved(): void
    {
        $giver = $this->userHolding('sari@t.local', 'est.approve', 'est.create');
        $delegate = $this->userHolding('budi@t.local', 'est.view');
        $someoneElse = $this->userHolding('drafter@t.local', 'est.create');

        $this->submittedBoq('RAB milik pemberi', $giver);
        $this->submittedBoq('RAB milik orang lain', $someoneElse);
        $this->delegate($giver, $delegate);

        $rows = $this->actingAs($delegate->fresh())->getJson('/api/core/inbox')->assertOk()->json('data');

        $this->assertCount(1, $rows);

        foreach ($rows as $row) {
            $this->actingAs($delegate->fresh())->postJson('/api/'.$row['approve_url'])->assertOk();
        }
    }

    /**
     * DAN PENYETUJU YANG MEMEGANG HAKNYA SENDIRI TIDAK KEHILANGAN BARIS. Ini
     * separuh lain dari penyempitan: menyembunyikan baris yang sebenarnya boleh
     * adalah cacat yang sama, hanya dengan tanda terbalik.
     */
    public function test_a_native_approver_keeps_every_row_after_receiving_a_delegation(): void
    {
        $admin = $this->userHolding('admin@t.local', 'est.approve', 'est.create');
        $director = $this->userHolding('direktur@t.local', 'est.approve', 'est.view');

        $this->submittedBoq('RAB diajukan admin', $admin);
        $this->delegate($admin, $director);

        $titles = array_column(ApprovalQueue::pending($director->fresh())['rows'], 'title');

        $this->assertSame(['RAB diajukan admin'], $titles);
    }

    /** Tanpa delegasi apa pun, antrean tidak berubah sedikit pun. */
    public function test_a_reader_with_no_delegation_sees_exactly_what_it_saw_before(): void
    {
        $approver = $this->userHolding('penyetuju@t.local', 'est.approve');
        $maker = $this->userHolding('drafter@t.local', 'est.create');

        $this->submittedBoq('RAB satu', $maker);
        $this->submittedBoq('RAB dua', $maker);

        $this->assertCount(2, ApprovalQueue::pending($approver)['rows']);
    }
}
