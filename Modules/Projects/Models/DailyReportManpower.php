<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Projects\Enums\DailyReportRole;

/**
 * Satu baris JUMLAH ORANG per jabatan (FM-10-12). Unik per
 * (daily_report_id, role_key); manpower_count laporan diturunkan dari jumlah
 * headcount baris-baris ini oleh DailyReportService — model ini tidak pernah
 * menghitung apa pun sendiri.
 */
class DailyReportManpower extends BaseModel
{
    protected $table = 'prj_daily_report_manpower';

    protected function casts(): array
    {
        return [
            'role_key' => DailyReportRole::class,
            'headcount' => 'integer',
        ];
    }

    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }
}
