<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Support\TokenScope;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * 403 yang MENYEBUT ability yang kurang (P-3d, perangkap A).
 *
 * Penolakannya sendiri terjadi di `TokenScope`, di dalam
 * `App\Models\User::hasPermissionTo()` — satu tempat yang dilewati setiap
 * pemeriksaan izin. Yang TIDAK bisa dilakukan di sana adalah menjelaskannya:
 * pada saat sebuah izin ditolak, yang memanggil bisa saja middleware rute
 * spatie (melempar `UnauthorizedException` berbahasa Inggris "User does not
 * have the right permissions.") atau sebuah controller yang memulangkan 403
 * buatannya sendiri. Keduanya benar bahwa panggilannya ditolak, dan keduanya
 * menyebut sebab yang SALAH kepada pemilik token: izin penggunanya utuh, yang
 * kurang adalah ability tokennya.
 *
 * Maka lapisan ini menukar BADAN jawaban 403 — dan hanya 403 — ketika, dan
 * hanya ketika, `TokenScope` benar-benar mencatat sebuah penolakan pada
 * permintaan ini. Permintaan yang ditolak karena izin penggunanya memang
 * kurang lewat apa adanya, dengan kalimatnya yang lama.
 *
 * DUA JALAN MASUK, DAN KEDUANYA HARUS DITANGANI. `Illuminate\Routing\Pipeline`
 * menangkap pengecualian di dalam `carry()` MILIK SETIAP PIPA, jadi
 * pengecualian yang dilempar middleware rute keluar lewat `$next($request)`
 * middleware INI sebagai pengecualian — bukan sebagai jawaban 403 yang sudah
 * dirender. Menangkap hanya salah satunya meninggalkan separuh kasus dalam
 * bahasa Inggris (637 rute memakai middleware rute; 215 tidak — diukur
 * 12 Sep 2026, `UngatedApiRouteCensusTest`).
 *
 * Dipasang pada GRUP `api` lewat `pushMiddlewareToGroup` di CoreServiceProvider
 * (bukan di `bootstrap/app.php`, yang tidak boleh disentuh paket ini), jadi ia
 * membungkus setiap rute di bawah `api/` tanpa satu baris pun per modul.
 */
class ExplainTokenScopeRefusal
{
    public function __construct(private readonly TokenScope $scope) {}

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            if ($this->isForbidden($e) && ($sentence = $this->scope->refusalSentence()) !== null) {
                return $this->refusal($sentence);
            }

            throw $e;
        }

        if ($response instanceof \Symfony\Component\HttpFoundation\Response
            && $response->getStatusCode() === Response::HTTP_FORBIDDEN
            && ($sentence = $this->scope->refusalSentence()) !== null) {
            return $this->refusal($sentence);
        }

        return $response;
    }

    private function isForbidden(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface && $e->getStatusCode() === Response::HTTP_FORBIDDEN;
    }

    /**
     * Bentuk badan sama dengan `ApiController::error()` — `message` + `errors`
     * — supaya `api.js` menampilkannya seperti setiap galat lain, dan supaya
     * klien API yang membaca `message` tidak perlu tahu lapisan ini ada.
     */
    private function refusal(string $sentence): JsonResponse
    {
        return response()->json([
            'message' => $sentence,
            'errors' => ['token_abilities' => $this->scope->deniedAbilities()],
        ], Response::HTTP_FORBIDDEN);
    }
}
