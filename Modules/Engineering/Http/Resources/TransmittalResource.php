<?php

namespace Modules\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\MaterialSubmittal;

class TransmittalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'direction' => $this->direction?->value,
            'direction_label' => $this->direction?->label(),
            'to_party' => $this->to_party,
            'transmittal_date' => $this->transmittal_date?->toDateString(),
            'notes' => $this->notes,
            'received_by' => $this->received_by,
            'received_at' => $this->received_at?->format('Y-m-d H:i'),
            'state_label' => $this->received_at !== null ? 'Diterima' : 'Belum diterima',
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                // The WIRE vocabulary, not the class name — the reverse of
                // TransmittalService::LINE_KINDS.
                'kind' => match ($line->document_type) {
                    DrawingSubmittal::class => 'drawing_submittal',
                    MaterialSubmittal::class => 'material_submittal',
                    default => 'lainnya',
                },
                'document_id' => $line->document_id,
                'document_code' => $line->document?->code,
                'description' => $line->description,
                'remarks' => $line->remarks,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
