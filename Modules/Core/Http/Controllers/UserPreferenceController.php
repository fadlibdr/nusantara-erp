<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Resources\UserPreferenceResource;
use Modules\Core\Models\UserPreference;
use Modules\Core\Support\UserPreferences;

/**
 * Preferensi PEMANGGIL SENDIRI (P1-C, T1C.1).
 *
 * Tanpa gerbang izin, dan tidak ada izin yang bisa menolongnya: tidak ada
 * parameter di kedua endpoint ini yang menyebut pengguna, jadi baris yang
 * dibaca dan ditulis selalu milik $request->user(). Pola yang sama dengan
 * kotak masuk (GET core/inbox) — "scoped to the caller in the controller, so
 * no permission gate applies".
 *
 * Yang menjaga endpoint tanpa gerbang ini dari menjadi penyimpanan bebas
 * adalah whitelist UserPreferences: kunci di luar daftar 422, dan setiap
 * nilai punya plafon byte sendiri di bawah 16 KB.
 */
class UserPreferenceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rows = UserPreference::query()
            ->where('user_id', $request->user()->getKey())
            ->orderBy('key')
            ->get();

        // meta.keys = whitelist-nya sendiri, supaya prefs.js tidak menyalin
        // daftar kunci ke klien (dua daftar yang bisa berselisih).
        return $this->ok(UserPreferenceResource::collection($rows), null, [
            'keys' => array_keys(UserPreferences::keys()),
            'max_bytes' => UserPreferences::MAX_BYTES,
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        if (! UserPreferences::has($key)) {
            $message = sprintf('Preferensi "%s" tidak dikenal.', $key);

            return $this->error($message, 422, ['key' => [$message]]);
        }

        // 'value' harus HADIR: tanpa pemeriksaan ini sebuah PUT tanpa badan
        // menyimpan null diam-diam, dan yang berikutnya membaca "pernah
        // memilih tidak ada" — bukan "belum pernah memilih".
        if (! $request->has('value')) {
            return $this->error('Nilai preferensi wajib diisi.', 422, ['value' => ['Nilai preferensi wajib diisi.']]);
        }

        $value = $request->input('value');
        $error = UserPreferences::reject($key, $value);

        if ($error !== null) {
            return $this->error($error, 422, ['value' => [$error]]);
        }

        $preference = UserPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->getKey(), 'key' => $key],
            ['value' => $value],
        );

        return $this->ok(UserPreferenceResource::make($preference));
    }
}
