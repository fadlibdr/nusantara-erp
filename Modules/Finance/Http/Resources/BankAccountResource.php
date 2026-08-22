<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'bank_name' => $this->bank_name,
            'account_no' => $this->account_no,
            'account_name' => $this->account_name,
            'coa_account_id' => $this->coa_account_id,
            'coa_account' => $this->whenLoaded('coaAccount', fn () => [
                'id' => $this->coaAccount->id,
                'code' => $this->coaAccount->code,
                'name' => $this->coaAccount->name,
            ]),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
