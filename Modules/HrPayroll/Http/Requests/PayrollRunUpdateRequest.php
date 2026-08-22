<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\PayrollRunType;

class PayrollRunUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $run = $this->route('payrollRun');

        return [
            'period_year' => ['sometimes', 'required', 'integer', 'between:2000,2100'],
            'period_month' => [
                'sometimes', 'required', 'integer', 'between:1,12',
                Rule::unique('hr_payroll_runs', 'period_month')
                    ->where(
                        fn ($query) => $query
                            ->where('period_year', $this->integer('period_year', (int) $run?->period_year))
                            ->where('run_type', $this->input('run_type', $run?->run_type?->value))
                            ->whereNull('deleted_at'),
                    )
                    ->ignore($run?->id),
            ],
            'run_type' => ['sometimes', 'required', Rule::enum(PayrollRunType::class)],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
