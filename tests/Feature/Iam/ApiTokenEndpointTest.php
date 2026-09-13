<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Http\Controllers\ApiTokenController;
use Modules\Iam\Http\Middleware\SessionOnly;
use Modules\Iam\Models\ApiToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Profil › Token API — pintu CRUD, dan empat janji yang dipaku literal (P-3d).
 *
 *   1. teks token TAMPIL SEKALI dan tidak pernah dipulangkan lagi
 *   2. ability = SUBSET izin pemanggil, tidak pernah lebih, tidak pernah `*`
 *   3. masa berlaku ≤ 1 tahun, ditegakkan di Request
 *   4. token tidak bisa mencetak token (SessionOnly)
 */
class ApiTokenEndpointTest extends ErpTestCase
{
    private function reader(): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('pembaca', 'web');
        $role->syncPermissions([
            Permission::findByName('fin.view', 'web'),
            Permission::findByName('prj.view', 'web'),
        ]);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pembaca', 'email' => 'pembaca@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole('pembaca');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_the_plaintext_token_is_returned_exactly_once(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $created = $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Integrasi akuntansi',
            'abilities' => ['fin.view'],
            'expires_in_days' => 90,
        ])->assertCreated();

        $plain = (string) $created->json('data.token');

        $this->assertNotSame('', $plain);
        $this->assertStringContainsString('|', $plain, 'teks token Sanctum berbentuk <id>|<rahasia>');
        $this->assertSame(ApiTokenController::SHOWN_ONCE, $created->json('data.shown_once'));

        // Yang tersimpan adalah sidik jarinya, bukan teksnya.
        $row = ApiToken::query()->firstOrFail();
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $row->token);

        // Dan daftar sesudahnya tidak pernah memulangkan teksnya lagi.
        $listed = $this->getJson('/api/iam/me/api-tokens')->assertOk();

        $this->assertSame('Integrasi akuntansi', $listed->json('data.tokens.0.name'));
        $this->assertNull($listed->json('data.tokens.0.token'));
        $this->assertStringNotContainsString($plain, $listed->getContent());
    }

    public function test_the_created_token_actually_works_and_carries_only_its_abilities(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $plain = (string) $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Integrasi akuntansi',
            'abilities' => ['fin.view'],
            'expires_in_days' => 365,
        ])->assertCreated()->json('data.token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/finance/journals')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/projects/baselines')->assertForbidden();
    }

    public function test_an_ability_the_caller_does_not_hold_is_refused_by_name(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Integrasi nakal',
            'abilities' => ['fin.view', 'fin.approve'],
            'expires_in_days' => 30,
        ])->assertStatus(422);

        $this->assertStringContainsString('«fin.approve»', implode(' ', $response->json('errors.abilities')));
        $this->assertStringContainsString('SUBSET izin pemiliknya', implode(' ', $response->json('errors.abilities')));
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_the_wildcard_ability_is_refused(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Integrasi tak terbatas',
            'abilities' => ['*'],
            'expires_in_days' => 30,
        ])->assertStatus(422);

        $this->assertStringContainsString('tanda bintang tidak diterima', implode(' ', $response->json('errors.abilities')));
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_a_lifetime_longer_than_a_year_is_refused_and_a_year_is_accepted(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $this->assertSame(365, ApiToken::MAX_LIFETIME_DAYS);

        $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Terlalu panjang', 'abilities' => ['fin.view'], 'expires_in_days' => 366,
        ])->assertStatus(422)->assertJsonValidationErrors('expires_in_days');

        $created = $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Setahun penuh', 'abilities' => ['fin.view'], 'expires_in_days' => 365,
        ])->assertCreated();

        $this->assertSame(
            now()->addDays(365)->toDateString(),
            substr((string) $created->json('data.expires_at'), 0, 10),
        );
    }

    public function test_a_token_without_an_expiry_cannot_be_asked_for(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Abadi', 'abilities' => ['fin.view'],
        ])->assertStatus(422)->assertJsonValidationErrors('expires_in_days');
    }

    public function test_revoking_a_token_stops_it_on_the_next_request(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $created = $this->postJson('/api/iam/me/api-tokens', [
            'name' => 'Integrasi akuntansi', 'abilities' => ['fin.view'], 'expires_in_days' => 30,
        ])->assertCreated();

        $plain = (string) $created->json('data.token');
        $id = (int) $created->json('data.id');

        $this->deleteJson("/api/iam/me/api-tokens/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Token «Integrasi akuntansi» dicabut. Klien yang masih memakainya akan mendapat 401 pada permintaan berikutnya.');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/finance/journals')->assertUnauthorized();
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_a_token_belonging_to_somebody_else_is_not_revocable_and_is_not_listed(): void
    {
        $mine = $this->reader();

        /** @var User $theirs */
        $theirs = User::query()->create([
            'name' => 'Orang lain', 'email' => 'lain@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $theirToken = $theirs->createToken('Milik orang lain', ['fin.view'], now()->addDays(30));
        $theirToken->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();

        Sanctum::actingAs($mine, ['*']);

        $this->getJson('/api/iam/me/api-tokens')->assertOk()->assertJsonCount(0, 'data.tokens');

        $this->deleteJson('/api/iam/me/api-tokens/'.$theirToken->accessToken->getKey())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Token itu tidak ada di daftar token Anda.');

        $this->assertSame(1, ApiToken::query()->count());
    }

    /** Daftar ability yang ditawarkan layar = izin pemanggil, bukan seluruh kosakata. */
    public function test_the_screen_is_offered_only_the_abilities_the_caller_holds(): void
    {
        $user = $this->reader();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/iam/me/api-tokens')->assertOk();

        $this->assertSame(['fin.view', 'prj.view'], $response->json('data.available_abilities'));
        $this->assertSame(365, $response->json('data.max_lifetime_days'));
        $this->assertSame(300, $response->json('data.rate_limit_per_minute'));
    }

    /**
     * SEBUAH TOKEN TIDAK BOLEH MENCETAK TOKEN.
     *
     * Tanpa `SessionOnly`, sebuah token "hanya baca keuangan" memanggil pintu
     * ini — yang tidak dijaga izin apa pun karena ia layanan mandiri — dan
     * mencetak token kedua dengan seluruh izin pemiliknya. Setiap pembatasan
     * yang dibangun paket ini akan berumur satu permintaan.
     */
    public function test_a_personal_token_cannot_mint_another_token_or_change_the_password(): void
    {
        $user = $this->reader();
        $issued = $user->createToken('Integrasi akuntansi', ['fin.view'], now()->addDays(30));
        $issued->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();
        $header = ['Authorization' => 'Bearer '.$issued->plainTextToken];

        foreach ([
            ['post', '/api/iam/me/api-tokens', ['name' => 'Eskalasi', 'abilities' => ['fin.view'], 'expires_in_days' => 365]],
            ['get', '/api/iam/me/api-tokens', []],
            ['put', '/api/iam/me/password', ['current_password' => 'password', 'password' => 'rahasia-baru-1', 'password_confirmation' => 'rahasia-baru-1']],
            ['put', '/api/iam/me/phone', ['phone_e164' => '+6281234567890', 'whatsapp_opt_in' => true]],
        ] as [$method, $uri, $payload]) {
            $response = $this->withHeaders($header)->{$method.'Json'}($uri, $payload);

            $response->assertForbidden();
            $this->assertSame(SessionOnly::REFUSAL, $response->json('message'), $method.' '.$uri);
        }

        $this->assertSame(1, ApiToken::query()->count());
    }

    /** Token sesi SPA memakai pintu yang sama dan tidak terhalang. */
    public function test_the_spa_session_token_may_still_use_the_screen(): void
    {
        $user = $this->reader();
        $user->forceFill(['password' => 'password'])->save();

        $token = (string) $this->postJson('/api/iam/auth/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/iam/me/api-tokens', [
                'name' => 'Integrasi akuntansi', 'abilities' => ['fin.view'], 'expires_in_days' => 30,
            ])
            ->assertCreated();
    }
}
