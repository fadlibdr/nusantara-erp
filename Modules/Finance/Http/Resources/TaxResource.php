<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'rate' => $this->rate,
            'tax_type' => $this->tax_type?->value,
            'tax_type_label' => $this->tax_type?->label(),
            'object_code' => $this->object_code,
            'coa_account_id' => $this->coa_account_id,
            'coa_account' => $this->whenLoaded('coaAccount', fn () => [
                'id' => $this->coaAccount->id,
                'code' => $this->coaAccount->code,
                'name' => $this->coaAccount->name,
            ]),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
