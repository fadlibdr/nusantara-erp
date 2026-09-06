<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu laporan bebas yang disimpan seseorang (P1-F).
 *
 * Bentuk `definition` TIDAK dijamin oleh model ini — ia divalidasi ulang
 * terhadap ReportableResources setiap kali dibaca untuk dijalankan, karena
 * katalog berubah lebih cepat daripada baris ini dan sebuah kolom yang dicabut
 * harus terbaca sebagai penolakan bernama, bukan sebagai kueri yang gagal.
 *
 * Cast 'json' pada keduanya dengan alasan yang sama seperti UserPreference:
 * json_decode(assoc) mengembalikan apa adanya, dan `shared_roles` yang null
 * (pribadi) tetap null alih-alih menjadi [].
 */
class SavedReport extends BaseModel
{
    protected $table = 'core_saved_reports';

    protected $casts = [
        'definition' => 'json',
        'shared_roles' => 'json',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function sharedRoles(): array
    {
        $roles = $this->shared_roles;

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }
}
