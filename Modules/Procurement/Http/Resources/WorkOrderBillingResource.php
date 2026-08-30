<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderBillingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'work_order_id' => $this->work_order_id,
            'work_order_code' => $this->relationLoaded('workOrder') ? $this->workOrder?->code : null,
            'billing_no' => $this->billing_no,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'total_amount' => $this->total_amount,
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'work_order_item_id' => $line->work_order_item_id,
                'description' => $line->relationLoaded('workOrderItem') ? $line->workOrderItem?->description : null,
                'rate_basis' => $line->relationLoaded('workOrderItem') ? $line->workOrderItem?->rate_basis?->value : null,
                'qty' => $line->qty,
                'amount' => $line->amount,
                'meter_start' => $line->meter_start,
                'meter_end' => $line->meter_end,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
