<?php

namespace Modules\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialSubmittalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'item_id' => $this->item_id,
            'item_code' => $this->whenLoaded('item', fn () => $this->item?->code),
            'material_name' => $this->material_name,
            'brand' => $this->brand,
            'spec_reference' => $this->spec_reference,
            'sample_attached' => (bool) $this->sample_attached,
            'submitted_at' => $this->submitted_at?->toDateString(),
            'reviewer_party' => $this->reviewer_party?->value,
            'reviewer_party_label' => $this->reviewer_party?->label(),
            'decision' => $this->decision?->value,
            'decision_label' => $this->decision?->label(),
            'decided_at' => $this->decided_at?->toDateString(),
            'notes' => $this->notes,
            'state_label' => $this->decision?->label() ?? 'Menunggu keputusan',
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
