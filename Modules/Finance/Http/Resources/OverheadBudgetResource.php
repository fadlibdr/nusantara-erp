<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;

class OverheadBudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'period_year' => $this->period_year,
            'total_amount' => $this->total_amount,
            'status' => $this->status?->value,
            // Dipancarkan seperti setiap resource dokumen lain (bandingkan
            // PurchaseOrderResource): cells.js merender kolom status sebagai
            // `row.status_label || …`, jadi tanpa baris ini SATU-SATUNYA daftar
            // dokumen di SPA yang mencetak "approved"/"cancelled" mentah adalah
            // layar OVB — pada paket yang mengirimnya (verifikasi F-2 putaran 2).
            'status_label' => $this->status?->label(),
            'notes' => $this->notes,
            // Pembatalan (verifikasi F-2): dokumen yang dibatalkan harus
            // membawa SEBABNYA ke layar, bukan hanya status 'cancelled'.
            'cancelled_at' => $this->cancelled_at?->toDateTimeString(),
            'cancellation_reason' => $this->cancellation_reason,
            'lines_count' => $this->whenLoaded('lines', fn () => $this->lines->count()),
            'lines' => OverheadBudgetLineResource::collection($this->whenLoaded('lines')),
            'approvals' => $this->whenLoaded('approvals', fn (): array => ApprovalTrail::map($this->approvals)),
        ];
    }
}
