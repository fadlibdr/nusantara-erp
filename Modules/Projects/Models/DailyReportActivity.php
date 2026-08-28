<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu baris URAIAN PEKERJAAN / PROGRESS / TARGET / HAMBATAN (FM-10-12).
 * wbs_task_id tanpa constraint: generate-WBS menghapus-dan-membangun-ulang
 * pohon tugas, dan baris uraian yang tugasnya hilang tetap terjadi.
 */
class DailyReportActivity extends BaseModel
{
    protected $table = 'prj_daily_report_activities';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function wbsTask(): BelongsTo
    {
        return $this->belongsTo(WbsTask::class, 'wbs_task_id');
    }
}
