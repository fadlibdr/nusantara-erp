<?php

namespace Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepreciationEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'depreciation_run_id' => $this->depreciation_run_id,
            'asset_id' => $this->asset_id,
            'asset' => AssetResource::make($this->whenLoaded('asset')),
            'amount' => $this->amount,
            'book_value_after' => $this->book_value_after,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
