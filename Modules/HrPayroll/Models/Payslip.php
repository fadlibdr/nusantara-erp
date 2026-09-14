<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Enums\OvertimeBasis;

class Payslip extends BaseModel
{
    protected $table = 'hr_payslips';

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'basic_salary' => 'decimal:2',
            'allowances' => 'array',
            'allowances_total' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            // F-5 — dasar bayar lembur, dibekukan pada slipnya (migrasi 001094).
            // NULL berarti slip dihitung sebelum 14 Sep 2026, bukan "tanpa lembur".
            'overtime_basis' => OvertimeBasis::class,
            'overtime_rate_detail' => 'array',
            'thr_amount' => 'decimal:2',
            'gross_income' => 'decimal:2',
            'bpjs' => 'array',
            'bpjs_employee_total' => 'decimal:2',
            'bpjs_company_total' => 'decimal:2',
            'ter_rate' => 'decimal:4',
            'pph21_amount' => 'decimal:2',
            'has_tax_id' => 'boolean',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
