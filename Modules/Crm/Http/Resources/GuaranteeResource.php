<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuaranteeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guarantee_type' => $this->guarantee_type?->value,
            'guarantee_type_label' => $this->guarantee_type?->label(),
            'number' => $this->number,
            'issuer' => $this->issuer,
            'contract_id' => $this->contract_id,
            'contract' => $this->whenLoaded('contract', fn () => [
                'id' => $this->contract->id,
                'code' => $this->contract->code,
                'title' => $this->contract->title,
            ]),
            'quotation_id' => $this->quotation_id,
            'quotation' => $this->whenLoaded('quotation', fn () => [
                'id' => $this->quotation->id,
                'code' => $this->quotation->code,
                'title' => $this->quotation->title,
            ]),
            'value' => $this->value,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Derived, never stored — the register cannot go stale on the one
            // fact it exists for.
            'is_expired' => $this->isExpired(),
            // Guarantee::daysRemaining(), not a second subtraction written
            // here: the printed register jaminan prints the same number in its
            // SISA HARI column, and a screen and a signed sheet disagreeing
            // about how many days of cover are left is the one thing this
            // register exists to prevent. The model also carries the Carbon
            // caveat — diffInDays' sign has changed between majors, and a
            // lapsed bond reading as days REMAINING fails silently.
            'days_left' => $this->when(
                $this->status?->isLive() && $this->end_date !== null,
                fn (): ?int => $this->daysRemaining(),
            ),
            'document_location' => $this->document_location,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
