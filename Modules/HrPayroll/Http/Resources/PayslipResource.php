<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayslipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'payroll_run' => PayrollRunResource::make($this->whenLoaded('payrollRun')),
            'employee_id' => $this->employee_id,
            'employee' => EmployeeResource::make($this->whenLoaded('employee')),
            'basic_salary' => $this->basic_salary,
            'allowances' => $this->allowances,
            'allowances_total' => $this->allowances_total,
            'overtime_hours' => $this->overtime_hours,
            'overtime_pay' => $this->overtime_pay,
            /*
             * F-5 — dengan dasar apa lembur slip ini dibayar. Dikirim bersama
             * LABEL-nya, seperti setiap enum lain di rumah ini: 'rata_jam_pertama'
             * di layar orang yang sedang memeriksa gaji seseorang adalah kebocoran
             * istilah basis data. null = slip dihitung sebelum 14 Sep 2026 dan
             * dasarnya memang tidak pernah dicatat — keadaan yang berbeda dari
             * 'tanpa_lembur', dan layar mengatakannya berbeda.
             */
            'overtime_basis' => $this->overtime_basis?->value,
            'overtime_basis_label' => $this->overtime_basis?->label(),
            'overtime_rate_detail' => $this->overtime_rate_detail,
            'thr_amount' => $this->thr_amount,
            'gross_income' => $this->gross_income,
            'bpjs' => $this->bpjs,
            'bpjs_employee_total' => $this->bpjs_employee_total,
            'bpjs_company_total' => $this->bpjs_company_total,
            'ter_category' => $this->ter_category,
            'ter_rate' => $this->ter_rate,
            'pph21_amount' => $this->pph21_amount,
            'total_deductions' => $this->total_deductions,
            'net_pay' => $this->net_pay,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
