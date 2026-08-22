<?php

namespace Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'useful_life_months_default' => $this->useful_life_months_default,
            'depreciation_account_hint' => $this->depreciation_account_hint,
            'accum_account_hint' => $this->accum_account_hint,
            'asset_account_hint' => $this->asset_account_hint,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
