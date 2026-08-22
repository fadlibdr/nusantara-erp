<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentWithholdingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ar_invoice_id' => $this->ar_invoice_id,
            'invoice_code' => $this->whenLoaded('invoice', fn () => $this->invoice->code),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'account_code' => $this->type?->accountCode(),
            'amount' => $this->amount,
            // Temuan #15: alasan tertulis adalah satu-satunya jejak audit
            // potongan non-pajak — layar harus bisa menampilkannya.
            'reason' => $this->reason,
            'certificate_no' => $this->certificate_no,
            'certificate_date' => $this->certificate_date?->toDateString(),
        ];
    }
}
