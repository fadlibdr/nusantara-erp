<?php

namespace Modules\Quality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Quality\Enums\ItemResult;

/**
 * One inspector verdict against one checklist item (ok/nok/na) with an optional
 * remark. A line row (no softDeletes).
 */
class InspectionResult extends BaseModel
{
    protected $table = 'qc_inspection_results';

    protected function casts(): array
    {
        return [
            'result' => ItemResult::class,
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id');
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplateItem::class, 'template_item_id');
    }
}
