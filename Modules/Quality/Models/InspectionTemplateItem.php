<?php

namespace Modules\Quality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * One butir of a checklist: what is checked, the acceptance criterion, and an
 * optional tolerance. A line row (no softDeletes).
 */
class InspectionTemplateItem extends BaseModel
{
    protected $table = 'qc_inspection_template_items';

    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplate::class, 'template_id');
    }
}
