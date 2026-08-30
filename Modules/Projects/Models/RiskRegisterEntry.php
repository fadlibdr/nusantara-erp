<?php

namespace Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Projects\Enums\RiskLevel;

/**
 * P6: one row of the IBPRP register (Permen PUPR 10/2021) — an activity, its
 * hazard, the initial L×S assessment, the controls, and the residual L×S.
 *
 * A register row, not a document: no code, no approval cycle. The scores are
 * STORED but written only by RiskRegisterService's arithmetic; the levels are
 * NOT stored — they derive from the score through RiskLevel::fromScore, the
 * single place the banding lives.
 */
class RiskRegisterEntry extends BaseModel
{
    use SoftDeletes;

    protected $table = 'prj_risk_register';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'likelihood' => 'integer',
            'severity' => 'integer',
            'initial_score' => 'integer',
            'residual_likelihood' => 'integer',
            'residual_severity' => 'integer',
            'residual_score' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function initialLevel(): RiskLevel
    {
        return RiskLevel::fromScore((int) $this->initial_score);
    }

    public function residualLevel(): ?RiskLevel
    {
        return $this->residual_score === null
            ? null
            : RiskLevel::fromScore((int) $this->residual_score);
    }
}
