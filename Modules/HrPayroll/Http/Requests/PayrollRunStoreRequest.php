<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\PayrollRunType;

class PayrollRunStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'period_year' => ['required', 'integer', 'between:2000,2100'],
            'period_month' => [
                'required', 'integer', 'between:1,12',
                // One run per period per type (regular and THR may coexist in a month).
                Rule::unique('hr_payroll_runs', 'period_month')
                    ->where(
                        fn ($query) => $query
                            ->where('period_year', $this->integer('period_year'))
                            ->where('run_type', $this->input('run_type', PayrollRunType::Regular->value))
                            ->whereNull('deleted_at'),
                    ),
            ],
            'run_type' => ['required', Rule::enum(PayrollRunType::class)],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
