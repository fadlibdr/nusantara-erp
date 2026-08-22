<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Enums\PostingStatus;

/**
 * One period's PSAK 115 revenue recognition (persentase penyelesaian).
 *
 * Draft while management reviews and adjusts EACs; posting writes the single
 * adjusting journal and locks it. One run per period, enforced by the schema.
 */
class RevenueRecognitionRun extends BaseModel
{
    use HasDocumentNumber;

    protected $table = 'fin_revenue_recognition_runs';

    public string $documentType = 'POC';

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'total_adjustment' => 'decimal:2',
            'posted_at' => 'datetime',
            'status' => PostingStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RevenueRecognitionLine::class, 'run_id');
    }

    public function isPosted(): bool
    {
        return $this->status === PostingStatus::Posted;
    }

    /** Last calendar day of the period — the adjusting journal's date. */
    public function periodEnd(): string
    {
        return sprintf('%04d-%02d-%02d', $this->period_year, $this->period_month,
            cal_days_in_month(CAL_GREGORIAN, $this->period_month, $this->period_year));
    }
}
