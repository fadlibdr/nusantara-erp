<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Core\Support\TokenScope;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Models\ApiToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * ABILITY YANG TIDAK DITEGAKKAN ADALAH KEBOHONGAN (P-3d, perangkap A).
 *
 * Matriksnya: untuk setiap ability yang diberikan, rute yang BUKAN miliknya
 * menjawab 403 dengan kalimat Indonesia yang menyebut ability yang kurang, dan
 * rute yang memang miliknya tetap bekerja. Ketiga BENTUK gerbang di aplikasi
 * ini ikut diuji, karena penegakan yang hanya menutup satu di antaranya adalah
 * penegakan yang bocor:
 *
 *   1. middleware rute `permission:` (bentuk yang paling banyak dipakai)
 *   2. pemeriksaan izin DI DALAM controller — mis. lampiran, yang menurunkan
 *      izinnya dari DOKUMEN, bukan dari rutenya
 *   3. `Gate::before` delegasi persetujuan — satu-satunya jalur pemberian yang
 *      tidak lewat hasPermissionTo() milik orang yang memakainya
 *
 * Semuanya lewat HTTP sungguhan dengan token sungguhan di header — bukan
 * `Sanctum::actingAs`, yang tidak pernah memancarkan `TokenAuthenticated` dan
 * karenanya akan menguji jalur yang bukan jalur produksi.
 */
class ApiTokenAbilityMatrixTest extends ErpTestCase
{
    /** @param  list<string>  $abilities */
    private function personalToken(User $user, array $abilities): string
    {
        $token = $user->createToken('Integrasi uji', $abilities, now()->addDays(30));
        $token->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();

        return $token->plainTextToken;
    }

    private function asToken(string $token, string $uri): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson($uri);
    }

    /**
     * Satu ability, satu rute miliknya, dan tiga rute yang bukan.
     *
     * fin.view membuka daftar jurnal DAN TIDAK membuka daftar pengguna, daftar
     * pelanggan, atau penulisan pelanggan — meski penggunanya (admin) memegang
     * SELURUH izin.
     */
    public function test_a_token_scoped_to_one_ability_reaches_only_that_ability_route(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['fin.view']);

        $this->asToken($token, '/api/finance/journals')->assertOk();

        $this->asToken($token, '/api/iam/users')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/crm/customers', ['name' => 'PT Uji'])
            ->assertForbidden();
    }

    /**
     * BATAS YANG DIKATAKAN APA ADANYA: ability menyempitkan GERBANG IZIN, dan
     * tidak menciptakan gerbang di tempat aplikasi ini sendiri tidak
     * menggerbangi apa pun.
     *
     * `GET crm/customers` hanya menuntut autentikasi — tidak ada izin yang
     * diperiksa rutenya maupun controllernya — jadi token terbatas milik
     * seseorang menjangkaunya persis seperti sesi peramban orang itu. Uji ini
     * ada supaya kalimatnya tidak pernah berubah diam-diam menjadi janji yang
     * lebih besar: layar Token API, PANDUAN-PENGGUNA §14 dan dokumen OpenAPI
     * menuliskan batas ini, dan `UngatedApiRouteCensusTest` menghitung
     * berapa banyak rute yang ada di dalamnya.
     */
    public function test_a_route_gated_by_nothing_but_authentication_stays_reachable(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['fin.view']);

        $this->asToken($token, '/api/crm/customers?per_page=1')->assertOk();
    }

    public function test_the_refusal_names_the_missing_ability_in_indonesian(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['fin.view']);

        $response = $this->asToken($token, '/api/iam/users')->assertForbidden();

        $message = (string) $response->json('message');

        $this->assertStringContainsString('«iam.view»', $message);
        $this->assertStringContainsString('Token API yang Anda pakai tidak memiliki ability', $message);
        $this->assertStringContainsString('Profil › Token API', $message);
        $this->assertSame(['iam.view'], $response->json('errors.token_abilities'));

        // Kalimat bawaan spatie yang berbahasa Inggris TIDAK boleh sampai ke
        // pemilik token: ia menyebut sebab yang salah (izin penggunanya utuh).
        $this->assertStringNotContainsString('does not have the right permissions', $message);
    }

    /**
     * Gerbang di DALAM controller, bukan di rutenya.
     *
     * `POST core/attachments` tidak membawa `permission:` sama sekali — izinnya
     * diturunkan dari jenis dokumennya (`fin.update` untuk tagihan vendor). Ia
     * salah satu rute TULIS yang akan lolos tanpa penjaga bila penegakan
     * ability dibaca dari parameter rute — daftar lengkapnya ada di
     * `UngatedApiRouteCensusTest::UNGATED_WRITES`.
     */
    public function test_an_in_controller_permission_check_is_narrowed_too(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['fin.view']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/core/attachments', [
                'document_type' => 'finance/ar-invoices',
                'document_id' => 1,
                'filename' => 'bukti.pdf',
                'content' => base64_encode('halo'),
            ])
            ->assertForbidden();

        $this->assertStringContainsString('«fin.update»', (string) $response->json('message'));
    }

    /**
     * TOKEN SPA HARUS TETAP BEKERJA PERSIS SEPERTI SEKARANG.
     *
     * `login` membuat token tanpa daftar ability, dan Sanctum menyimpannya
     * sebagai `["*"]`. Kalau baris itu ikut dipersempit, seluruh aplikasi mati.
     */
    public function test_the_spa_session_token_still_reaches_everything(): void
    {
        $user = $this->adminUser();
        $user->forceFill(['password' => 'password'])->save();

        $login = $this->postJson('/api/iam/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $token = (string) $login->json('data.token');

        $this->asToken($token, '/api/finance/journals')->assertOk();
        $this->asToken($token, '/api/iam/users')->assertOk();
        $this->asToken($token, '/api/crm/customers?per_page=1')->assertOk();
    }

    /**
     * V-TOKEN-1: PANGGILAN PERTAMA SETIAP KLIEN HARUS MENYEBUT ABILITY TOKENNYA.
     *
     * `GET iam/auth/me` adalah satu dari 20 endpoint terkurasi, dan dokumennya
     * menyebutnya "panggilan pertama setiap klien". Ia memulangkan
     * `permissions` — 94 nama untuk akun admin, `fin.approve` di antaranya —
     * yang menjawab pertanyaan "apa yang dipegang ORANGNYA", bukan "apa yang
     * boleh dilakukan TOKEN INI". Sebelum field ini ada, tidak ada satu pun
     * pintu yang memberi tahu sebuah token apa abilitynya: `iam/me/api-tokens`
     * ditutup `SessionOnly` justru untuk token. Penulis integrasi membaca
     * `permissions`, menulis kliennya terhadap daftar itu, lalu setiap
     * panggilan di luar abilitynya menjawab 403 «…» pada saat berjalan.
     */
    public function test_auth_me_names_the_abilities_of_the_token_that_asked(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['prj.view']);

        $response = $this->asToken($token, '/api/iam/auth/me')->assertOk();

        $this->assertSame(['prj.view'], $response->json('data.token_abilities'));

        // Dan yang lama tetap ada, dengan artinya yang lama: izin ORANGNYA.
        $permissions = (array) $response->json('data.permissions');
        $this->assertContains('fin.approve', $permissions);
        $this->assertNotContains('fin.approve', (array) $response->json('data.token_abilities'));
    }

    /** Token cangkang SPA menyebut `*`, dan itulah yang membuatnya tidak dipersempit. */
    public function test_auth_me_says_the_spa_token_carries_the_wildcard(): void
    {
        $user = $this->adminUser();
        $user->forceFill(['password' => 'password'])->save();

        $login = $this->postJson('/api/iam/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $response = $this->asToken((string) $login->json('data.token'), '/api/iam/auth/me')->assertOk();

        $this->assertSame(['*'], $response->json('data.token_abilities'));
    }

    /**
     * Baris ORANG LAIN tidak menjawab pertanyaan tentang token pemanggil.
     *
     * `UserResource` yang sama dipulangkan daftar pengguna sampai 200 baris;
     * `token_abilities` menjawab "apa yang boleh SAYA lakukan", jadi ia null
     * di setiap baris yang bukan baris pemanggilnya.
     */
    public function test_another_users_row_never_claims_to_know_its_token(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['iam.view']);

        /** @var User $other */
        $other = User::query()->create([
            'name' => 'Orang lain', 'email' => 'lain@test.local', 'password' => 'password', 'is_active' => true,
        ]);

        $response = $this->asToken($token, '/api/iam/users/'.$other->id)->assertOk();

        $this->assertNull($response->json('data.token_abilities'));
    }

    /**
     * V-TOKEN-2: 403 YANG SAMPAI KE SINI SEBAGAI PENGECUALIAN, bukan sebagai
     * jawaban yang sudah dirender.
     *
     * `Illuminate\Routing\Pipeline::carry()` membungkus setiap pipa dan
     * `prepareDestination()` membungkus controllernya, jadi dalam jalur biasa
     * `UnauthorizedException` spatie SUDAH menjadi jawaban 403 sebelum
     * middleware ini melihatnya — cabang `catch` tidak pernah berjalan, dan
     * sampai uji ini menghapusnya seluruhnya tetap hijau. Yang membuatnya
     * berjalan adalah penangan pengecualian yang MELEMPAR ULANG, yaitu
     * `withoutExceptionHandling()` di sini dan setiap pemanggil yang
     * memasangnya. Cabang itu dipertahankan sebagai lapis kedua, dan sekarang
     * ada yang memerah bila ia dihapus.
     */
    public function test_a_refusal_that_arrives_as_an_exception_still_names_the_ability(): void
    {
        $this->withoutExceptionHandling();

        $user = $this->adminUser();
        $token = $this->personalToken($user, ['fin.view']);

        $response = $this->asToken($token, '/api/iam/users')->assertForbidden();

        $this->assertStringContainsString('«iam.view»', (string) $response->json('message'));
        $this->assertSame(['iam.view'], $response->json('errors.token_abilities'));
    }

    /**
     * ABILITY ADALAH SUBSET, BUKAN PEMBERIAN — arah kedua.
     *
     * Izin yang dicabut dari peran mencabut aksesnya token SEKETIKA, tanpa satu
     * baris pun yang menyentuh tokennya, dan kalimat penolakannya BUKAN kalimat
     * token: yang kurang memang izin penggunanya.
     */
    public function test_revoking_the_permission_from_the_role_revokes_the_token_immediately(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['fin.view']);

        $this->asToken($token, '/api/finance/journals')->assertOk();

        /** @var Role $role */
        $role = Role::findByName('admin', 'web');
        $role->revokePermissionTo(Permission::findByName('fin.view', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->asToken($token, '/api/finance/journals')->assertForbidden();

        $this->assertStringNotContainsString('tidak memiliki ability', (string) $response->json('message'));
    }

    /**
     * Ability yang TIDAK dimiliki penggunanya tidak pernah memberi apa pun,
     * bahkan bila baris tokennya menuliskannya — pintu pembuatan menolak
     * ability semacam itu (ApiTokenScreenTest), dan penegakan ini adalah
     * jaring keduanya untuk baris yang ditulis dengan cara lain.
     */
    public function test_an_ability_the_user_never_held_grants_nothing(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('pembaca', 'web');
        $role->syncPermissions([Permission::findByName('fin.view', 'web')]);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pembaca', 'email' => 'pembaca@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole('pembaca');

        $token = $this->personalToken($user, ['fin.view', 'iam.view']);

        $this->asToken($token, '/api/finance/journals')->assertOk();
        $this->asToken($token, '/api/iam/users')->assertForbidden();
    }

    /**
     * Dua ability yang kurang pada satu permintaan disebut KEDUANYA, urut.
     *
     * `core/attachments` index memeriksa satu izin; rute yang memakai
     * `permission:a|b` memeriksa dua. Yang diuji di sini adalah bentuk
     * kalimatnya, lewat satu permintaan yang menyentuh dua pemeriksaan.
     */
    public function test_the_refusal_lists_every_ability_the_request_was_denied(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, ['fin.view']);

        $scope = app(TokenScope::class);
        $scope->allows($user, 'iam.view');
        $scope->allows($user, 'crm.view');

        // Tanpa token yang diingat DAN tanpa currentAccessToken pada instans
        // ini, tidak ada yang dipersempit — jadi kalimatnya kosong. Itulah
        // keadaan setiap perintah artisan dan setiap pekerja antrean.
        $this->assertNull($scope->refusalSentence());
    }

    /** `*` adalah satu-satunya ability yang tidak boleh diberikan sebuah token pribadi. */
    public function test_the_wildcard_ability_constant_is_the_same_on_both_sides(): void
    {
        $this->assertSame(ApiToken::ABILITY_ALL, TokenScope::ABILITY_ALL);
        $this->assertSame('*', TokenScope::ABILITY_ALL);
        $this->assertFalse(TokenScope::isGrantableAbility('*'));
        $this->assertFalse(TokenScope::isGrantableAbility('fin.*'));
        $this->assertTrue(TokenScope::isGrantableAbility('fin.view'));
    }
}
