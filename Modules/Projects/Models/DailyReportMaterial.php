<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Models\Item;

class DailyReportMaterial extends BaseModel
{
    protected $table = 'prj_daily_report_materials';

    protected function casts(): array
    {
        return [
            'qty_used' => 'decimal:3',
        ];
    }

    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
