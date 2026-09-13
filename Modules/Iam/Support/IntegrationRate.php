<?php

namespace Modules\Iam\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Iam\Models\ApiToken;

/**
 * DUA BATAS LAJU YANG TIDAK SALING MENABRAK (P-3d, perangkap H).
 *
 * Ledger pemilik ROADMAP-HASHMICRO §5 baris 10: "CORS / laju token integrasi →
 * kosong / 300 per menit". Batas yang sudah ada — `config('erp.security.
 * api_rate_limit')`, 120 per menit — dipasang untuk peramban, dan tiga sesi
 * peramban beruntun milik satu orang sudah cukup untuk menyentuhnya. Menaikkan
 * angka itu menjadi 300 untuk semua orang bukan yang diminta ledger, dan
 * menurunkan token integrasi ke 120 akan mencekik integrasi yang justru
 * dibangun paket ini.
 *
 * Maka: DUA EMBER, DAN KUNCINYA BERBEDA.
 *
 *   token pribadi (`kind = personal`)  300/menit, per TOKEN
 *   selain itu                         120/menit, apa adanya seperti sebelumnya
 *
 * Kuncinya per token, bukan per pengguna: seorang operator yang membuka
 * peramban sambil integrasinya berjalan tidak boleh memakan jatah integrasi itu
 * dan sebaliknya, dan dua integrasi milik orang yang sama tidak berbagi ember.
 * Permintaan tanpa token sah tetap dikunci dengan IP, persis seperti sebelumnya.
 *
 * HARGANYA, dikatakan apa adanya: pembatas laju berjalan SEBELUM `auth:sanctum`
 * (ia middleware grup `api`, auth adalah middleware rute), jadi ia harus
 * menyelesaikan tokennya sendiri — satu pencarian indeks primer per permintaan
 * yang membawa token, di samping pencarian yang sama yang dilakukan Guard
 * beberapa mikrodetik kemudian. Menghindarinya berarti menebak jenis token dari
 * bentuk teksnya, dan tebakan yang salah membagi jatah yang salah.
 */
final class IntegrationRate
{
    /**
     * Kalimat 429 — DAN apa yang harus dilakukan klien.
     *
     * `%d` = batas per menit yang benar-benar berlaku untuk pemanggil ini.
     * Header `Retry-After` ikut dipasang pembatas laju Laravel; kalimat ini
     * menyebutnya supaya klien yang hanya mencetak `message` tetap tahu.
     */
    public const TOO_MANY = 'Batas laju API terlampaui: maksimum %d permintaan per menit untuk kredensial ini. '
        .'Tunggu sampai jendela satu menit berikutnya — header Retry-After menyebutkan berapa detik lagi — lalu ulangi permintaan yang sama.';

    /**
     * Token dari permintaan, dengan aturan header yang SAMA dengan yang
     * dipasang `IamServiceProvider` ke Sanctum.
     *
     * Satu definisi, dua pemakai: Guard (lewat
     * `Sanctum::getAccessTokenFromRequestUsing`) dan pembatas laju. Dua salinan
     * akan berpisah pada hari sebuah header ketiga ditambahkan, dan yang
     * berpisah diam-diam adalah jatah lajunya.
     */
    public static function tokenStringFrom(Request $request): ?string
    {
        return $request->header('X-Api-Token') ?: $request->bearerToken();
    }

    public static function limitFor(Request $request): Limit
    {
        $token = self::personalTokenFrom($request);

        if ($token !== null) {
            $perMinute = self::integrationPerMinute();

            return self::limit($perMinute)->by('api-token:'.$token->getKey());
        }

        $perMinute = self::browserPerMinute();

        return self::limit($perMinute)->by((string) ($request->user()?->id ?? $request->ip()));
    }

    public static function browserPerMinute(): int
    {
        return (int) config('erp.security.api_rate_limit', 120);
    }

    public static function integrationPerMinute(): int
    {
        return (int) config('erp.security.integration_rate_limit', 300);
    }

    private static function limit(int $perMinute): Limit
    {
        return Limit::perMinute($perMinute)->response(
            static fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => sprintf(self::TOO_MANY, $perMinute)],
                429,
                $headers,
            )
        );
    }

    /**
     * Baris token pribadi yang sah untuk permintaan ini, atau null.
     *
     * Token yang tidak ditemukan, token sesi SPA, dan permintaan tanpa token
     * sama-sama memulangkan null — semuanya memakai ember peramban. Yang
     * SENGAJA tidak diperiksa di sini adalah kedaluwarsanya: token yang mati
     * ditolak Guard dengan 401, dan permintaan yang ditolak tetap harus
     * memakan jatah laju seseorang, kalau tidak menebak token adalah serangan
     * tanpa biaya.
     */
    private static function personalTokenFrom(Request $request): ?ApiToken
    {
        $plain = self::tokenStringFrom($request);

        if ($plain === null || $plain === '') {
            return null;
        }

        $token = ApiToken::findToken($plain);

        return $token instanceof ApiToken && $token->isPersonal() ? $token : null;
    }
}
