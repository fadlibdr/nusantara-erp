<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyReportMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'daily_report_id' => $this->daily_report_id,
            'item_id' => $this->item_id,
            'qty_used' => $this->qty_used,
            'unit' => $this->unit,
        ];
    }
}
