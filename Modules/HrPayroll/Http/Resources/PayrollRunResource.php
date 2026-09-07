<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'run_type' => $this->run_type?->value,
            'run_type_label' => $this->run_type?->label(),
            'payment_date' => $this->payment_date?->toDateString(),
            'total_gross' => $this->total_gross,
            'total_deductions' => $this->total_deductions,
            'total_net' => $this->total_net,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'notes' => $this->notes,
            'payslips_count' => $this->whenCounted('payslips'),
            'payslips' => PayslipResource::collection($this->whenLoaded('payslips')),
            // Jejak persetujuan, bentuk PaymentResource — satu perender di SPA
            // (approvalTimeline) untuk semua dokumen; hanya bila show() memuatnya (T3.3).
            // F-1 — satu perender jejak untuk 25 resource (Core\Http\Resources\
            // ApprovalTrail), supaya "Budi a.n. Sari" muncul di semuanya dan
            // bukan di dua puluh empat di antaranya.
            'approvals' => $this->whenLoaded('approvals', fn (): array => ApprovalTrail::map($this->approvals)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
