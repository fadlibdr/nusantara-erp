<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu preferensi milik satu pengguna (P1-C). Kuncinya ber-whitelist di
 * Modules\Core\Support\UserPreferences; barisnya ditulis hanya oleh
 * UserPreferenceController atas nama pemanggil sendiri.
 *
 * Cast 'json' dan bukan 'array': nilainya kadang SKALAR ('compact' untuk
 * kepadatan), dan cast 'array' mengubah skalar menjadi bentuk yang bukan
 * dikirim klien. json_decode(assoc) mengembalikan apa adanya — string tetap
 * string, daftar tetap daftar.
 */
class UserPreference extends BaseModel
{
    protected $table = 'core_user_preferences';

    protected $casts = [
        'value' => 'json',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
