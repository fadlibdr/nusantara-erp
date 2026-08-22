<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'code' => $this->employee->code,
                'name' => $this->employee->name,
            ]),
            'certificate_type' => $this->certificate_type?->value,
            'certificate_type_label' => $this->certificate_type?->label(),
            'name' => $this->name,
            'number' => $this->number,
            'issuer' => $this->issuer,
            'issued_date' => $this->issued_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            // Negative = lapsed, null = tidak kedaluwarsa. Computed here so the
            // list can badge urgency without re-deriving dates client-side.
            'days_to_expiry' => $this->daysToExpiry(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
