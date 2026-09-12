<?php

namespace Tests\Feature\Iam;

use Illuminate\Http\Request;
use Modules\Iam\Models\ApiToken;
use Modules\Iam\Support\IntegrationRate;
use Tests\ErpTestCase;

/**
 * DUA EMBER LAJU, DAN YANG SATU TIDAK MENCEKIK YANG LAIN (P-3d, perangkap H).
 *
 * Ledger pemilik ROADMAP-HASHMICRO §5 baris 10: "CORS / laju token integrasi →
 * kosong / 300 per menit". Batas peramban yang sudah ada (120/menit) tidak
 * boleh ikut naik, dan token integrasi tidak boleh ikut turun.
 *
 * Angka 300 dan 120 dipaku LITERAL di sini. Uji yang membaca keduanya dari
 * `config()` — benda yang sama yang dibacanya kode — akan tetap hijau ketika
 * seseorang mengetik 30.
 */
class IntegrationRateLimitTest extends ErpTestCase
{
    public function test_the_two_limits_are_three_hundred_and_one_hundred_twenty(): void
    {
        $this->assertSame(300, IntegrationRate::integrationPerMinute());
        $this->assertSame(120, IntegrationRate::browserPerMinute());
    }

    public function test_a_personal_token_gets_its_own_bucket_of_three_hundred(): void
    {
        $user = $this->adminUser();
        $first = $user->createToken('Integrasi satu', ['fin.view'], now()->addDays(30));
        $first->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();
        $second = $user->createToken('Integrasi dua', ['fin.view'], now()->addDays(30));
        $second->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();

        $limits = [];

        foreach ([$first, $second] as $issued) {
            $request = Request::create('/api/finance/journals', 'GET');
            $request->headers->set('Authorization', 'Bearer '.$issued->plainTextToken);
            $limits[] = IntegrationRate::limitFor($request);
        }

        $this->assertSame(300, $limits[0]->maxAttempts);
        $this->assertSame(300, $limits[1]->maxAttempts);
        $this->assertSame('api-token:'.$first->accessToken->getKey(), $limits[0]->key);

        // EMBER PER TOKEN, BUKAN PER PENGGUNA: dua integrasi milik orang yang
        // sama tidak berbagi jatah, dan peramban orang itu juga tidak.
        $this->assertNotSame($limits[0]->key, $limits[1]->key);
    }

    public function test_a_session_token_keeps_the_browser_bucket_of_one_hundred_twenty(): void
    {
        $user = $this->adminUser();
        $issued = $user->createToken('api');
        $issued->accessToken->forceFill(['kind' => ApiToken::KIND_SESSION])->save();

        $request = Request::create('/api/finance/journals', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$issued->plainTextToken);

        $limit = IntegrationRate::limitFor($request);

        $this->assertSame(120, $limit->maxAttempts);
        $this->assertStringStartsNotWith('api-token:', $limit->key);
    }

    public function test_a_request_with_no_token_at_all_keeps_the_browser_bucket(): void
    {
        $request = Request::create('/api/iam/auth/login', 'POST');

        $limit = IntegrationRate::limitFor($request);

        $this->assertSame(120, $limit->maxAttempts);
    }

    /**
     * Yang dilihat integrasi ketika batasnya tersentuh: 429, satu kalimat
     * Indonesia yang menyebut angkanya, dan `Retry-After`.
     *
     * Batasnya diturunkan ke 2 untuk uji ini — mengetuk 301 kali hanya untuk
     * melihat bentuk jawabannya adalah biaya tanpa informasi tambahan; bahwa
     * ANGKANYA 300 dipaku uji pertama di berkas ini.
     */
    public function test_the_four_twenty_nine_says_what_happened_in_indonesian(): void
    {
        config(['erp.security.integration_rate_limit' => 2]);

        $user = $this->adminUser();
        $issued = $user->createToken('Integrasi uji', ['fin.view'], now()->addDays(30));
        $issued->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();

        $header = ['Authorization' => 'Bearer '.$issued->plainTextToken];

        $this->withHeaders($header)->getJson('/api/finance/journals')->assertOk();
        $this->withHeaders($header)->getJson('/api/finance/journals')->assertOk();

        $response = $this->withHeaders($header)->getJson('/api/finance/journals');

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');

        $message = (string) $response->json('message');

        $this->assertStringContainsString('Batas laju API terlampaui', $message);
        $this->assertStringContainsString('maksimum 2 permintaan per menit', $message);
        $this->assertStringContainsString('Retry-After', $message);
        $this->assertStringNotContainsString('Too Many Attempts', $message);
    }
}
