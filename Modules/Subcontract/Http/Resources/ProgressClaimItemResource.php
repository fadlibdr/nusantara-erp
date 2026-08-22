<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressClaimItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'progress_claim_id' => $this->progress_claim_id,
            'subcontract_item_id' => $this->subcontract_item_id,
            'subcontract_item' => $this->whenLoaded('subcontractItem', fn () => [
                'id' => $this->subcontractItem->id,
                'wbs_code' => $this->subcontractItem->wbs_code,
                'description' => $this->subcontractItem->description,
                'amount' => $this->subcontractItem->amount,
            ]),
            'prev_progress_pct' => $this->prev_progress_pct,
            'current_progress_pct' => $this->current_progress_pct,
            'period_progress_pct' => $this->period_progress_pct,
            'amount' => $this->amount,
        ];
    }
}
