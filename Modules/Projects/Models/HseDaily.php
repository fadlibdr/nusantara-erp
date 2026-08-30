<?php

namespace Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * P6: formulir K3 harian (FM-10-13) — toolbox meeting, hitungan APD per
 * kategori (baris data), temuan & tindak lanjut.
 *
 * Not Approvable — like the laporan harian it stands beside, it RECORDS what
 * happened on site that day; nobody approves a toolbox meeting into existence.
 *
 * daily_report_id is the laporan harian of the SAME project and date, resolved
 * by HseDailyService (and back-filled by DailyReportService when the report is
 * created later) — never typed by a client.
 */
class HseDaily extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_hse_daily';

    public string $documentType = 'HSE';

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'toolbox_attendees' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function apd(): HasMany
    {
        return $this->hasMany(HseDailyApd::class, 'hse_daily_id')->orderBy('id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(HseDailyFinding::class, 'hse_daily_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
