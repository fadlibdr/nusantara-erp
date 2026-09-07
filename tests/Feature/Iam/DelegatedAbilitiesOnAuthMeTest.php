<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * SESI HARUS TAHU HAK APA YANG SEDANG DIPINJAMNYA (verifikasi F-1 putaran 2).
 *
 * Aplikasi layar tunggal menggerbangi setiap layar dan setiap tombol pada
 * `user.permissions` dari GET /api/iam/auth/me, dan daftar itu adalah
 * getAllPermissions() milik Spatie — ia TIDAK melewati Gate::before, jadi
 * sebuah ability yang dipinjamkan delegasi tidak pernah muncul di sana.
 *
 * Akibatnya diukur di peramban 7 Sep 2026, pada login finance@nusantara.test
 * yang memegang delegasi lingkup penuh dari Administrator Sistem dan NOL izin
 * approve miliknya sendiri: grup Ringkasan di bilah samping berisi
 * ['Beranda','Dasbor','Tenggat','Kalender','Laporan Bebas'] — tanpa "Tugas
 * Saya"; #/dashboard tidak menyebut kata "persetujuan" satu kali pun; dan pada
 * dokumen CTI/2026/VIII/0002 yang MEMANG boleh diputuskannya, tombolnya hanya
 * ['Cetak'] sementara admin pada dokumen yang sama melihat
 * ['Cetak','Setujui','Tolak']. Servernya mengizinkan; layarnya tidak pernah
 * menawarkan.
 *
 * DUA HAL YANG SENGAJA TIDAK DILAKUKAN DI SINI:
 *
 *   1. `permissions` TIDAK DILEBARKAN. Daftar itu menjawab "apa yang dipegang
 *      orang ini", dan melebarkannya menjadikan jawaban itu bohong — layar
 *      Pengaturan Matriks Persetujuan menggerbangi dirinya pada
 *      <awalan>.approve-director dan servernya sengaja memakai holdsNatively()
 *      di sana: sebuah delegasi meminjamkan hak MENYETUJUI DOKUMEN, bukan hak
 *      menulis ulang apa arti menyetujui.
 *   2. Yang dipinjam dikirim DI FIELD SENDIRI beserta nama pemberinya, supaya
 *      layar dapat mengatakan "a.n. Sari" alih-alih diam.
 */
class DelegatedAbilitiesOnAuthMeTest extends ErpTestCase
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

    private function delegate(User $giver, User $delegate, ?string $scope = null): ApprovalDelegation
    {
        /** @var ApprovalDelegation $row */
        $row = ApprovalDelegation::query()->create([
            'giver_user_id' => $giver->id,
            'delegate_user_id' => $delegate->id,
            'scope' => $scope,
            'starts_at' => now()->subDay()->toDateString(),
        ]);
        ApprovalDelegations::flushMemo();

        return $row;
    }

    /**
     * SEORANG DELEGAT MURNI — nol izin approve sendiri — mendapat abilitynya
     * di auth/me, lengkap dengan nama pemberinya.
     */
    public function test_the_me_endpoint_carries_the_abilities_a_delegation_lends(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari@t.local', 'est.approve', 'est.approve-director', 'hr.approve');
        $delegate = $this->userHolding('Budi Pembaca', 'budi@t.local', 'est.view', 'hr.view', 'core.view');
        $this->delegate($giver, $delegate);

        $body = $this->actingAs($delegate->fresh())->getJson('/api/iam/auth/me')->assertOk()->json('data');

        $this->assertSame([
            'est.approve' => ['Sari Direktur'],
            'est.approve-director' => ['Sari Direktur'],
            'hr.approve' => ['Sari Direktur'],
        ], $body['delegated_permissions']);

        // …dan `permissions` tetap menjawab pertanyaannya sendiri.
        $this->assertSame(
            ['core.view', 'est.view', 'hr.view'],
            array_values(array_filter($body['permissions'], fn (string $p): bool => ! str_contains($p, 'approve'))),
        );
        $this->assertSame([], array_values(array_filter(
            $body['permissions'],
            fn (string $p): bool => str_contains($p, 'approve'),
        )));
    }

    /**
     * PEMBACA TANPA DELEGASI MENDAPAT DAFTAR KOSONG — bukan null, dan bukan
     * sesuatu yang membuat layar menawarkan tombol.
     */
    public function test_a_reader_without_a_delegation_is_lent_nothing(): void
    {
        $reader = $this->userHolding('Rina Pembaca', 'rina@t.local', 'est.view', 'core.view');

        $body = $this->actingAs($reader)->getJson('/api/iam/auth/me')->assertOk()->json('data');

        $this->assertSame([], $body['delegated_permissions']);
    }

    /**
     * ABILITY YANG SUDAH DIPEGANG SENDIRI BUKAN PINJAMAN.
     *
     * Aturannya harus sama persis dengan actingForId(): baris jejaknya berbunyi
     * "Budi", bukan "Budi a.n. Sari", ketika Budi memakai haknya sendiri. Kalau
     * field ini menyebutnya pinjaman, tombolnya akan berkata "a.n. Sari" untuk
     * persetujuan yang tidak pernah menyentuh hak Sari.
     */
    public function test_an_ability_the_delegate_already_holds_is_not_reported_as_lent(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari2@t.local', 'est.approve', 'hr.approve');
        $delegate = $this->userHolding('Budi Penyetuju', 'budi2@t.local', 'est.approve', 'hr.view');
        $this->delegate($giver, $delegate);

        $body = $this->actingAs($delegate->fresh())->getJson('/api/iam/auth/me')->assertOk()->json('data');

        $this->assertSame(['hr.approve' => ['Sari Direktur']], $body['delegated_permissions']);
        $this->assertContains('est.approve', $body['permissions']);
    }

    /**
     * DELEGASI BERLINGKUP HANYA MEMINJAMKAN LINGKUPNYA — dan delegasi dari
     * orang yang tidak memegang haknya tidak meminjamkan apa pun, persis
     * seperti grants().
     */
    public function test_the_lent_list_obeys_scope_and_what_the_giver_actually_holds(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari3@t.local', 'est.approve', 'hr.approve');
        $kosong = $this->userHolding('Tanpa Hak', 'kosong@t.local', 'prc.view');
        $delegate = $this->userHolding('Budi Pembaca', 'budi3@t.local', 'est.view', 'core.view');

        $this->delegate($giver, $delegate, 'est');
        $this->delegate($kosong, $delegate, 'prc');

        $body = $this->actingAs($delegate->fresh())->getJson('/api/iam/auth/me')->assertOk()->json('data');

        $this->assertSame(['est.approve' => ['Sari Direktur']], $body['delegated_permissions']);
    }

    /**
     * DAFTAR PENGGUNA TIDAK MEMBAYARNYA. Field ini menjawab "apa yang boleh
     * SAYA lakukan", jadi ia hanya dihitung untuk baris pemanggilnya sendiri —
     * 200 baris daftar pengguna tidak boleh membayar satu pemeriksaan delegasi
     * per baris.
     */
    public function test_the_user_listing_does_not_compute_it_for_other_rows(): void
    {
        $giver = $this->userHolding('Sari Direktur', 'sari4@t.local', 'est.approve');
        $delegate = $this->userHolding('Budi Pembaca', 'budi4@t.local', 'est.view');
        $admin = $this->userHolding('Admin Sistem', 'admin4@t.local', 'iam.view');
        $this->delegate($giver, $delegate);

        $rows = $this->actingAs($admin)->getJson('/api/iam/users?per_page=100')->assertOk()->json('data');

        foreach ($rows as $row) {
            $this->assertSame([], $row['delegated_permissions'], "baris {$row['email']}");
        }
    }
}
