<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor' => VendorResource::make($this->whenLoaded('vendor')),
            'project_id' => $this->project_id,
            'evaluated_by' => $this->evaluated_by,
            'evaluator_name' => $this->whenLoaded('evaluator', fn () => $this->evaluator?->name),
            'period' => $this->period,
            'quality_score' => (int) $this->quality_score,
            'delivery_score' => (int) $this->delivery_score,
            'price_score' => (int) $this->price_score,
            'service_score' => (int) $this->service_score,
            'total_score' => $this->total_score,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
