<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubcontractAddendumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'subcontract_id' => $this->subcontract_id,
            'subcontract' => $this->whenLoaded('subcontract', fn () => [
                'id' => $this->subcontract->id,
                'code' => $this->subcontract->code,
                'title' => $this->subcontract->title,
                'value' => $this->subcontract->value,
                'original_value' => $this->subcontract->original_value,
            ]),
            'addendum_date' => $this->addendum_date?->toDateString(),
            'title' => $this->title,
            'description' => $this->description,
            'reason' => $this->reason,
            'change_type' => $this->change_type?->value,
            'change_type_label' => $this->change_type?->label(),
            'value_change' => $this->value_change,
            'needs_director_approval' => (bool) $this->needs_director_approval,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'wbs_code' => $item->wbs_code,
                'description' => $item->description,
                'qty' => $item->qty,
                'unit' => $item->unit,
                'unit_price' => $item->unit_price,
                'amount' => $item->amount,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
