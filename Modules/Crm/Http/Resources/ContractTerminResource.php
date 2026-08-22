<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractTerminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'termin_no' => (int) $this->termin_no,
            'name' => $this->name,
            'percent' => $this->percent,
            'amount' => $this->amount,
            'billing_condition' => $this->billing_condition,
            // Pola "retensi sebagai termin" — prefill "Tagih termin ini" mematikan
            // potongan retensi per invoice untuk kontrak yang memuat flag ini.
            'is_retention' => (bool) $this->is_retention,
            'due_date' => $this->due_date?->toDateString(),
            'billed_at' => $this->billed_at?->toDateString(),
            'is_billed' => $this->billed_at !== null,
            // Due and unbilled — the state the billing queue is built on, so the
            // contract screen can flag it without asking the queue endpoint.
            'is_due' => $this->isDue(),
        ];
    }
}
