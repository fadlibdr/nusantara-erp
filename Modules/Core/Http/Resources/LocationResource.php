<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'parent_code' => $this->whenLoaded('parent', fn () => $this->parent?->code),
            'parent_name' => $this->whenLoaded('parent', fn () => $this->parent?->name),
            'kind' => $this->kind?->value,
            'kind_label' => $this->kind?->label(),
            'code' => $this->code,
            'name' => $this->name,
            'path' => $this->whenLoaded('parent', fn () => $this->path()),
            'sort_order' => (int) $this->sort_order,
            'children_count' => $this->whenCounted('children'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
