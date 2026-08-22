<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Assets\Enums\DepreciationRunStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

class DepreciationRun extends BaseModel
{
    use HasDocumentNumber;

    protected $table = 'ast_depreciation_runs';

    public string $documentType = 'DPR';

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'total_amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'status' => DepreciationRunStatus::class,
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class, 'depreciation_run_id');
    }

    public function isPosted(): bool
    {
        return $this->status === DepreciationRunStatus::Posted;
    }

    /**
     * "2026-06" — handy sort/display key for Finance's journal import.
     */
    public function periodLabel(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }
}
