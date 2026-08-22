<?php

namespace Modules\ServiceDesk\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Models\Item;

class FieldReportPart extends BaseModel
{
    protected $table = 'svc_field_report_parts';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function fieldReport(): BelongsTo
    {
        return $this->belongsTo(FieldReport::class, 'field_report_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
