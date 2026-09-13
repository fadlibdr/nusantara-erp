<?php

namespace Modules\Iam\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Iam\Http\Requests\ApiTokenStoreRequest;
use Modules\Iam\Models\ApiToken;

/**
 * Profil › Token API — kredensial milik ORANGNYA SENDIRI.
 *
 * Tanpa gerbang izin, dengan alasan yang sama seperti `me/password` dan
 * `me/preferences`: setiap baris yang dibaca dan ditulis di sini adalah baris
 * pemanggil. Yang menjaganya bukan izin melainkan `SessionOnly` — sebuah token
 * tidak boleh mencetak token.
 *
 * TEKS TOKEN TAMPIL SEKALI. Yang disimpan Sanctum adalah `hash('sha256', …)`;
 * teks aslinya hanya ada di dalam jawaban `POST` ini dan tidak pernah bisa
 * dipulangkan lagi oleh siapa pun, termasuk administrator. Itu bukan
 * kenyamanan yang hilang, itu satu-satunya hal yang membuat kolom di basis data
 * tidak setara dengan kata sandi.
 */
class ApiTokenController extends ApiController
{
    /** Kalimat yang dibaca layar sesudah token dibuat — dipaku uji dan harness. */
    public const SHOWN_ONCE = 'Salin token ini sekarang. Ia tidak akan ditampilkan lagi — '
        .'yang tersimpan di server hanya sidik jarinya, jadi tidak ada seorang pun yang bisa membacakannya kembali untuk Anda.';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $tokens = ApiToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->personal()
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $token): array => $this->describe($token))
            ->all();

        return $this->ok([
            'tokens' => $tokens,
            // Ability yang BOLEH dipilih orang ini = izin yang dipegangnya hari
            // ini. Layar tidak menyusun daftar ini sendiri: sebuah daftar klien
            // akan menawarkan izin yang tidak dimiliki pemakainya dan
            // menghasilkan 422 yang tampak seperti kesalahan aplikasi.
            'available_abilities' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'max_lifetime_days' => ApiToken::MAX_LIFETIME_DAYS,
            'rate_limit_per_minute' => (int) config('erp.security.integration_rate_limit', 300),
        ]);
    }

    public function store(ApiTokenStoreRequest $request): JsonResponse
    {
        $user = $request->user();

        $abilities = array_values(array_unique(array_map(
            static fn (string $ability): string => trim($ability),
            $request->input('abilities'),
        )));

        $issued = $user->createToken(
            trim((string) $request->input('name')),
            $abilities,
            now()->addDays((int) $request->integer('expires_in_days')),
        );

        $issued->accessToken->forceFill(['kind' => ApiToken::KIND_PERSONAL])->save();

        return $this->created([
            'token' => $issued->plainTextToken,
            'shown_once' => self::SHOWN_ONCE,
        ] + $this->describe($issued->accessToken->fresh()), 'Token API dibuat.');
    }

    /**
     * Mencabut = MENGHAPUS BARISNYA.
     *
     * Bukan kolom `revoked_at` yang ditinggalkan berdiri: sebuah baris yang
     * masih bisa diautentikasi Guard sementara sebuah kolom berkata "dicabut"
     * adalah tepat bentuk kegagalan diam yang paket ini ada untuk
     * menghindarinya. Sejarah pemakaiannya memang ikut hilang; yang tidak
     * hilang adalah jejak audit permintaan itu sendiri.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $user = $request->user();

        $row = ApiToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->personal()
            ->find($token);

        // 404 dan bukan 403 untuk token orang lain: jawaban yang berbeda
        // memberi tahu pemanggil id token mana yang ada di sistem.
        if ($row === null) {
            return $this->error('Token itu tidak ada di daftar token Anda.', 404);
        }

        $name = (string) $row->name;
        $row->delete();

        return $this->ok(null, "Token «{$name}» dicabut. Klien yang masih memakainya akan mendapat 401 pada permintaan berikutnya.");
    }

    /** @return array<string, mixed> */
    private function describe(ApiToken $token): array
    {
        return [
            'id' => $token->getKey(),
            'name' => (string) $token->name,
            'abilities' => $token->abilityList(),
            'created_at' => $token->created_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            // Kalimat, bukan boolean: layar tidak boleh menyusun "kedaluwarsa"
            // dari tanggal sendiri dan berbeda pendapat dengan Guard.
            'expired' => $token->expires_at !== null && $token->expires_at->isPast(),
        ];
    }
}
