<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaborClaimItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'labor_contract_item_id' => $this->labor_contract_item_id,
            'labor_contract_item' => $this->whenLoaded('laborContractItem', fn () => [
                'id' => $this->laborContractItem->id,
                'description' => $this->laborContractItem->description,
                'qty' => $this->laborContractItem->qty,
                'unit' => $this->laborContractItem->unit,
                'unit_rate' => $this->laborContractItem->unit_rate,
            ]),
            'qty_prev' => $this->qty_prev,
            'qty_this' => $this->qty_this,
            'amount' => $this->amount,
        ];
    }
}
