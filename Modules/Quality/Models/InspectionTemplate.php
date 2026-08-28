<?php

namespace Modules\Quality\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Quality\Enums\InspectionStage;

/**
 * P1-QC: one inspection checklist (code Q1..Q31) for a work package at a stage.
 * `code` is operator-owned (typed, unique), not a minted document number — the
 * library imports from XLSX through document-import keyed on it.
 */
class InspectionTemplate extends BaseModel
{
    use SoftDeletes;

    protected $table = 'qc_inspection_templates';

    protected function casts(): array
    {
        return [
            'stage' => InspectionStage::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
