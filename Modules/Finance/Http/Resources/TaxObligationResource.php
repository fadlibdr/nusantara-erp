<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxObligationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tax_type' => $this->tax_type?->value,
            'tax_type_label' => $this->tax_type?->label(),
            'masa_year' => $this->masa_year,
            'masa_month' => $this->masa_month,
            'name' => $this->name,
            'due_date' => $this->due_date?->toDateString(),
            'amount' => $this->amount,
            'ntpn' => $this->ntpn,
            'disetor_date' => $this->disetor_date?->toDateString(),
            'dilapor_date' => $this->dilapor_date?->toDateString(),
            'journal_id' => $this->journal_id,
            'journal' => $this->whenLoaded('journal', fn () => $this->journal === null ? null : [
                'id' => $this->journal->id,
                'code' => $this->journal->code,
            ]),
            'notes' => $this->notes,
            'status' => $this->resource->status(),
            'status_label' => $this->resource->statusLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
