<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RetentionReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subcontract_id' => $this->subcontract_id,
            'release_date' => $this->release_date?->toDateString(),
            'amount' => $this->amount,
            'notes' => $this->notes,
            // Filled only when the masa-pemeliharaan gate was overridden.
            'override_reason' => $this->override_reason,
            // The payable this release raised: where the money is actually
            // disbursed from, and the document to cancel if it was a mistake.
            'ap_bill_id' => $this->ap_bill_id,
            'ap_bill_code' => $this->whenLoaded('apBill', fn () => $this->apBill?->code),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
