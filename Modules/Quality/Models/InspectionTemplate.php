<?php

namespace Modules\Quality\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Enums\TemplateKind;

/**
 * P1-QC: one inspection checklist (code Q1..Q31) for a work package at a stage.
 * `code` is operator-owned (typed, unique), not a minted document number — the
 * library imports from XLSX through document-import keyed on it.
 *
 * P6: `jenis` (TemplateKind) — 'quality' is the Q1..Q31 library; '5r' is the
 * housekeeping patrol checklist, riding the very same inspection machinery.
 */
class InspectionTemplate extends BaseModel
{
    use SoftDeletes;

    protected $table = 'qc_inspection_templates';

    protected function casts(): array
    {
        return [
            'stage' => InspectionStage::class,
            'jenis' => TemplateKind::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
