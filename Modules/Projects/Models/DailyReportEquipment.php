<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu baris ALAT-ALAT (FM-10-12): apa yang bekerja di lapangan hari itu.
 * asset_id adalah rujukan lintas modul (ast_assets) tanpa constraint —
 * description yang dicetak, sehingga baris tetap berbunyi walau asetnya
 * kelak dihapus.
 */
class DailyReportEquipment extends BaseModel
{
    protected $table = 'prj_daily_report_equipment';

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'hours' => 'decimal:2',
        ];
    }

    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }
}
