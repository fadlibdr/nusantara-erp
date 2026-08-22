<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'nik_ktp' => $this->nik_ktp,
            'npwp' => $this->npwp,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'ptkp_status' => $this->ptkp_status?->value,
            'ptkp_status_label' => $this->ptkp_status?->label(),
            'ter_category' => $this->ptkp_status?->terCategory(),
            'join_date' => $this->join_date?->toDateString(),
            'employment_type' => $this->employment_type?->value,
            'employment_type_label' => $this->employment_type?->label(),
            'pkwt_basis' => $this->pkwt_basis?->value,
            'pkwt_basis_label' => $this->pkwt_basis?->label(),
            'pkwt_end_date' => $this->pkwt_end_date?->toDateString(),
            'position' => $this->position,
            'department' => $this->department,
            'base_salary' => $this->base_salary,
            'fixed_allowances' => $this->fixed_allowances,
            'fixed_allowances_total' => $this->fixedAllowancesTotal(),
            'bpjs_kesehatan_no' => $this->bpjs_kesehatan_no,
            'bpjs_tk_no' => $this->bpjs_tk_no,
            'bank_name' => $this->bank_name,
            'bank_account_no' => $this->bank_account_no,
            'bank_account_name' => $this->bank_account_name,
            'status' => $this->status,
            'resign_date' => $this->resign_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
