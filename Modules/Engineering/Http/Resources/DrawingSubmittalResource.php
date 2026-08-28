<?php

namespace Modules\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrawingSubmittalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'drawing_id' => $this->drawing_id,
            'drawing_number' => $this->whenLoaded('drawing', fn () => $this->drawing?->number),
            'drawing_title' => $this->whenLoaded('drawing', fn () => $this->drawing?->title),
            'project_id' => $this->whenLoaded('drawing', fn () => $this->drawing?->project_id),
            'revision' => $this->revision,
            'submitted_at' => $this->submitted_at?->toDateString(),
            'reviewer_party' => $this->reviewer_party?->value,
            'reviewer_party_label' => $this->reviewer_party?->label(),
            'decision' => $this->decision?->value,
            'decision_label' => $this->decision?->label(),
            'decided_at' => $this->decided_at?->toDateString(),
            'notes' => $this->notes,
            // Derived state for lists: menunggu keputusan / decided / digantikan.
            'state_label' => $this->superseded_at !== null
                ? 'Digantikan'
                : ($this->decision?->label() ?? 'Menunggu keputusan'),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'superseded_by_id' => $this->superseded_by_id,
            'superseded_by_code' => $this->whenLoaded('supersededBy', fn () => $this->supersededBy?->code),
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
