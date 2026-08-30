<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu perubahan tarif pajak lewat Pengaturan (P8, D5). Baris ditulis oleh
 * RateHistoryService dari dalam SettingService::set — tidak ada endpoint tulis,
 * dan tidak ada satu perhitungan dokumen pun yang membacanya: snapshot per
 * dokumen tetap sumber kebenaran.
 */
class RateHistoryEntry extends BaseModel
{
    protected $table = 'core_rate_history';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
