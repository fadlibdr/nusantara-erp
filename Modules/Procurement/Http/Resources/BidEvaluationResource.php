<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BidEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rfq_id' => $this->rfq_id,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'rab_amount' => $this->rab_amount,
            'offered_amount' => $this->offered_amount,
            'harga_score' => $this->harga_score,
            'mutu_score' => $this->mutu_score,
            'waktu_score' => $this->waktu_score,
            'keuangan_score' => $this->keuangan_score,
            'k3_score' => $this->k3_score,
            'weighted_score' => $this->weighted_score,
            'rank' => $this->rank,
            'notes' => $this->notes,
        ];
    }
}
