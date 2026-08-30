<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MethodLibraryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'category' => $this->category,
            'work_package' => $this->work_package,
            'title' => $this->title,
            'version' => $this->version,
            'summary' => $this->summary,
            'effective_date' => $this->effective_date?->toDateString(),
            // Diturunkan, tidak disimpan: satu kolom boolean "berlaku" yang bisa
            // ikut basi adalah persis cara dua versi terbaca sama-sama berlaku.
            'is_current' => $this->isCurrent(),
            'superseded_by_id' => $this->superseded_by_id,
            'superseded_by' => $this->whenLoaded('supersededBy', fn () => $this->supersededBy === null ? null : [
                'id' => $this->supersededBy->id,
                'code' => $this->supersededBy->code,
                'version' => $this->supersededBy->version,
            ]),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
