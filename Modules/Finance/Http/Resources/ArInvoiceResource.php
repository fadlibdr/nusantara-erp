<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
            ]),
            'contract_id' => $this->contract_id,
            'contract' => $this->whenLoaded('contract', fn () => [
                'id' => $this->contract->id,
                'code' => $this->contract->code,
                'title' => $this->contract->title,
            ]),
            'termin_id' => $this->termin_id,
            'termin' => $this->whenLoaded('termin', fn () => $this->termin === null ? null : [
                'id' => $this->termin->id,
                'termin_no' => $this->termin->termin_no,
                'name' => $this->termin->name,
                'percent' => $this->termin->percent,
            ]),
            'project_id' => $this->project_id,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'description' => $this->description,
            'dpp' => $this->dpp,
            'ppn_rate' => $this->ppn_rate,
            'ppn_amount' => $this->ppn_amount,
            'retention_withheld' => $this->retention_withheld,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'outstanding' => $this->resource->outstanding(),
            'faktur_pajak_no' => $this->faktur_pajak_no,
            'terbilang' => $this->terbilang,
            'paid_at' => $this->paid_at?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'retentions' => ArRetentionResource::collection($this->whenLoaded('retentions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
