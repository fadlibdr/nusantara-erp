<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => ItemCategoryResource::make($this->whenLoaded('category')),
            'unit' => $this->unit,
            'barcode' => $this->barcode,
            'item_type' => $this->item_type?->value,
            'item_type_label' => $this->item_type?->label(),
            'min_stock' => $this->min_stock,
            'avg_cost' => $this->avg_cost,
            'last_price' => $this->last_price,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
