<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttendanceRecapStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')],
            'period_year' => ['required', 'integer', 'between:2000,2100'],
            'period_month' => [
                'required', 'integer', 'between:1,12',
                Rule::unique('hr_attendance_recaps', 'period_month')->where(
                    fn ($query) => $query
                        ->where('employee_id', $this->integer('employee_id'))
                        ->where('period_year', $this->integer('period_year')),
                ),
            ],
            'work_days' => ['required', 'integer', 'between:0,31'],
            'present_days' => ['required', 'integer', 'between:0,31'],
            'sick_days' => ['nullable', 'integer', 'between:0,31'],
            'leave_days' => ['nullable', 'integer', 'between:0,31'],
            'alpha_days' => ['nullable', 'integer', 'between:0,31'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $accounted = $this->integer('present_days')
                + $this->integer('sick_days')
                + $this->integer('leave_days')
                + $this->integer('alpha_days');

            if ($accounted > $this->integer('work_days')) {
                $validator->errors()->add(
                    'present_days',
                    'Present + sick + leave + alpha days may not exceed the work days of the period.',
                );
            }
        });
    }
}
