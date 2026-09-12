<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Modules\Iam\Models\ApiToken;
use Tests\ErpTestCase;

/**
 * KEDALUWARSA PUNYA DUA KUNCI, DAN YANG GLOBAL MEMBUNUH YANG PER-TOKEN (P-3d).
 *
 * `Laravel\Sanctum\Guard::isValidAccessToken()` menuntut KEDUANYA benar:
 *
 *     (! $this->expiration || $token->created_at->gt(now()->subMinutes($this->expiration)))
 *  && (! $token->expires_at || ! $token->expires_at->isPast())
 *
 * dan `config/sanctum.php` menyetel `'expiration' => 720` (12 jam). Tanpa
 * paket ini, sebuah token pribadi yang layarnya berkata "berlaku sampai 12
 * September 2027" BERHENTI BEKERJA 12 JAM SESUDAH DIBUAT: fitur yang ada,
 * terlihat benar, dan diam-diam tidak berfungsi. Uji pertama di bawah GAGAL
 * (401) sebelum `Sanctum::authenticateAccessTokensUsing()` dipasang.
 *
 * Pilihan (a) dari dua yang mungkin: plafon global 720 menit TETAP berlaku
 * untuk token sesi SPA (`kind` null atau `session` — setiap baris yang sudah
 * ada di produksi termasuk di sini, jadi TIDAK ADA backfill dan tidak ada
 * baris yang menjadi abadi), dan token pribadi (`kind = personal`) dinilai
 * HANYA dari `expires_at` miliknya sendiri. Pilihan (b) — `expiration` global
 * dijadikan null — akan membuat setiap baris produksi yang `expires_at`-nya
 * kosong hidup selamanya sampai sebuah backfill mengejarnya; itu regresi
 * keamanan yang dibayar di muka untuk kenyamanan satu baris konfigurasi.
 *
 * Guard `sanctum` di `config/auth.php` sengaja TIDAK punya provider (komentar
 * di berkas itu menjelaskan alasannya: spatie akan mencari izin di guard
 * 'sanctum'), jadi `hasValidProvider()` selalu true dan `$isValid` yang
 * dioper ke callback tidak membawa syarat lain yang ikut hilang.
 */
class ApiTokenExpiryTest extends ErpTestCase
{
    private function personalToken(User $user, ?string $expiresAt = '+365 days'): string
    {
        $token = $user->createToken('Integrasi akuntansi', ['iam.view'], $expiresAt === null ? null : now()->parse($expiresAt));
        $token->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();

        return $token->plainTextToken;
    }

    /** Token sesi SPA: persis seperti Modules\Iam\Http\Controllers\AuthController::login menulisnya. */
    private function sessionToken(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_the_global_ceiling_is_twelve_hours_and_that_is_what_makes_this_test_matter(): void
    {
        // Angka yang dipaku adalah angka yang dibaca Guard — bukan angka yang
        // ditulis ulang di uji ini. 720 menit = 12 jam.
        $this->assertSame(720, (int) Config::get('sanctum.expiration'));
    }

    public function test_a_personal_token_still_authenticates_after_thirteen_hours(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user);

        $this->travel(13)->hours();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/iam/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_a_personal_token_still_authenticates_after_eleven_months(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user);

        $this->travel(334)->days();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/iam/auth/me')
            ->assertOk();
    }

    public function test_a_fresh_spa_session_token_authenticates(): void
    {
        $user = $this->adminUser();

        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($user))
            ->getJson('/api/iam/auth/me')
            ->assertOk();
    }

    /**
     * SATU permintaan per uji kedaluwarsa, dan itu bukan gaya penulisan.
     *
     * `RequestGuard` menyimpan pengguna yang sudah diselesaikannya di dalam
     * instans guard, dan seluruh uji berbagi SATU aplikasi — jadi permintaan
     * kedua di uji yang sama dijawab dari memo itu tanpa `isValidAccessToken()`
     * pernah dipanggil lagi. Versi pertama uji ini memanggil `auth/me` sekali
     * sebelum `travel()` untuk "membuktikan tokennya hidup", dan mendapat 200
     * sesudah 13 jam — bukan karena plafonnya tidak berlaku, tetapi karena
     * tidak ada yang memeriksanya. Kesegaran token dipakukan di uji sendiri
     * (test_a_fresh_spa_session_token_authenticates).
     */
    public function test_a_spa_session_token_still_dies_after_thirteen_hours(): void
    {
        $user = $this->adminUser();
        $token = $this->sessionToken($user);

        $this->travel(13)->hours();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/iam/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Baris yang SUDAH ADA di produksi hari ini punya `kind` NULL. Ia harus
     * tetap tunduk pada plafon 12 jam — kalau tidak, paket ini memanjangkan
     * umur setiap token yang sudah beredar tanpa seorang pun memintanya.
     */
    public function test_a_row_with_no_kind_at_all_is_treated_as_a_session_token(): void
    {
        $user = $this->adminUser();
        $token = $user->createToken('api');
        $token->accessToken->forceFill(['kind' => null])->save();

        $this->travel(13)->hours();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/iam/auth/me')
            ->assertUnauthorized();
    }

    public function test_a_personal_token_past_its_own_expiry_is_refused(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, '+30 days');

        $this->travel(31)->days();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/iam/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Token pribadi TANPA expires_at adalah token abadi. Pintu pembuatannya
     * tidak pernah membuatnya, tetapi kalau satu pun muncul (tulisan tangan di
     * tinker, baris yang di-`kind`-kan salah), ia ditolak — bukan diterima
     * selamanya karena plafon globalnya sudah tidak berlaku baginya.
     */
    public function test_a_personal_token_without_an_expiry_is_refused_outright(): void
    {
        $user = $this->adminUser();
        $token = $this->personalToken($user, null);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/iam/auth/me')
            ->assertUnauthorized();
    }
}
