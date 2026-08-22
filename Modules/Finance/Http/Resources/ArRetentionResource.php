<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArRetentionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'project_id' => $this->project_id,
            'source_invoice_id' => $this->source_invoice_id,
            'amount' => $this->amount,
            'released' => (bool) $this->released,
            'released_at' => $this->released_at?->toDateString(),
        ];
    }
}
