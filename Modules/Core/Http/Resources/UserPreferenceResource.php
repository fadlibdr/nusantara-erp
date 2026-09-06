<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu preferensi pengguna. `value` dikirim APA ADANYA (daftar, objek, atau
 * skalar) — SPA menyimpannya kembali persis seperti yang diterimanya, jadi
 * membungkusnya di sini akan membuat baca dan tulis berbeda bentuk.
 */
class UserPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
