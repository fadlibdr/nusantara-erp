<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\ApprovalDelegation;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Core\Support\TokenScope;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Models\ApiToken;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * DELEGASI TIDAK PERNAH MELEBIHI ABILITY TOKEN YANG MEMANGGIL (P-3d).
 *
 * `Gate::before` delegasi persetujuan adalah SATU-SATUNYA jalur pemberian di
 * aplikasi ini yang tidak lewat `hasPermissionTo()` milik orang yang
 * memakainya — delegatnya memang TIDAK memegang izin itu, itulah gunanya
 * delegasi. Penyempitan ability yang dipasang di `App\Models\User` karena itu
 * tidak pernah dipanggil untuknya, dan tanpa baris tambahan di gerbang itu
 * sebuah token "hanya baca keuangan" milik integrasi pihak ketiga yang
 * kebetulan dipegang seorang delegat bisa MENYETUJUI dokumen — tanpa satu pun
 * uji ability yang melihatnya.
 *
 * Dua arah dipaku: delegasi tetap bekerja tanpa token (sesi peramban, perintah
 * artisan), dan berhenti bekerja lewat token yang tidak membawa abilitynya.
 */
class ApprovalDelegationTokenScopeTest extends ErpTestCase
{
    private User $giver;

    private User $delegate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->giver = $this->userHolding('pemberi@test.local', 'fin.approve');
        $this->delegate = $this->userHolding('delegat@test.local', 'fin.view');

        ApprovalDelegation::query()->create([
            'giver_user_id' => $this->giver->id,
            'delegate_user_id' => $this->delegate->id,
            'scope' => null,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
            'reason' => 'Cuti tahunan',
        ]);

        ApprovalDelegations::flushMemo();
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

    /**
     * Token yang dipakai permintaan ini, diingat PERSIS seperti yang dilakukan
     * pendengar `TokenAuthenticated` di CoreServiceProvider.
     *
     * `Sanctum::actingAs($user, $abilities)` sengaja TIDAK dipakai: ia memasang
     * sebuah Mockery dari model token, bukan baris sungguhan, dan TokenScope
     * hanya pernah mendengarkan baris yang benar-benar diautentikasi Guard.
     *
     * @param  list<string>  $abilities
     */
    private function tokened(User $user, array $abilities): User
    {
        $issued = $user->createToken('Integrasi uji', $abilities, now()->addDays(30));
        $issued->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();

        app(TokenScope::class)->remember($issued->accessToken->fresh());

        return $user;
    }

    public function test_the_delegate_may_approve_without_a_token_at_all(): void
    {
        $this->assertTrue(Gate::forUser($this->delegate)->allows('fin.approve'));
    }

    public function test_a_token_without_the_approve_ability_cannot_use_the_delegation(): void
    {
        $delegate = $this->tokened($this->delegate, ['fin.view']);

        $this->assertFalse(Gate::forUser($delegate)->allows('fin.approve'));
    }

    public function test_a_token_that_carries_the_approve_ability_still_uses_the_delegation(): void
    {
        $delegate = $this->tokened($this->delegate, ['fin.view', 'fin.approve']);

        $this->assertTrue(Gate::forUser($delegate)->allows('fin.approve'));
    }

    /**
     * V-TOKEN-3: TOKEN PEMANGGIL TIDAK MEMPERSEMPIT JAWABAN TENTANG ORANG LAIN.
     *
     * `TokenScope::tokenFor()` memeriksa PEMILIK baris tokennya, dan cabang itu
     * tidak dijaga apa pun sampai uji ini: sebuah permintaan di aplikasi ini
     * bisa menanyakan izin ORANG LAIN — delegasi memeriksa izin PEMBERINYA
     * (`ApprovalDelegations::holdsNatively($grantor, …)`), maker-checker
     * memeriksa izin pengajunya — dan token delegat tidak boleh menjadi
     * jawaban tentang pemberi.
     *
     * Bentuknya dipilih supaya mutasi bisa membedakannya: token delegat
     * membawa `fin.approve` SAJA, sedangkan yang ditanyakan adalah
     * `prc.approve` milik PEMBERI. Tanpa pemeriksaan pemilik, daftar yang
     * dipinjamkan kehilangan `prc.approve` — layar "a.n. Sari" berhenti
     * menyebut separuh haknya, dan jawaban yang salah itu menyangkut orang
     * yang tokennya tidak ada hubungannya dengan permintaan ini.
     */
    public function test_the_callers_token_never_narrows_answers_about_someone_else(): void
    {
        $this->giver->givePermissionTo('prc.approve');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        ApprovalDelegations::flushMemo();

        $delegate = $this->tokened($this->delegate, ['fin.approve']);

        $lent = ApprovalDelegations::lentAbilitiesFor($delegate);

        $this->assertArrayHasKey('fin.approve', $lent);
        $this->assertArrayHasKey('prc.approve', $lent, 'izin PEMBERI dipersempit oleh token DELEGAT');
        $this->assertSame([$this->giver->name], $lent['prc.approve']);
    }

    /** Token cangkang SPA (`["*"]`) tidak mempersempit apa pun, termasuk delegasi. */
    public function test_a_wildcard_token_still_uses_the_delegation(): void
    {
        $delegate = $this->tokened($this->delegate, ['*']);

        $this->assertTrue(Gate::forUser($delegate)->allows('fin.approve'));
    }
}
