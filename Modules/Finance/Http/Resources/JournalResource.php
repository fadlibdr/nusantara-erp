<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'journal_date' => $this->journal_date?->toDateString(),
            'description' => $this->description,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'created_by' => $this->created_by,
            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'total_debit' => $this->whenLoaded('lines', fn () => round((float) $this->lines->sum('debit'), 2)),
            'total_credit' => $this->whenLoaded('lines', fn () => round((float) $this->lines->sum('credit'), 2)),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
