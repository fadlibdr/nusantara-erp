<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type?->value,
            'account_type_label' => $this->account_type?->label(),
            'parent_id' => $this->parent_id,
            'is_postable' => (bool) $this->is_postable,
            'normal_balance' => $this->normal_balance?->value,
            'is_active' => (bool) $this->is_active,
            'children' => self::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
