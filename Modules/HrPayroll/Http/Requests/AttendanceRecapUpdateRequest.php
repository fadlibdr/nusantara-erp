<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AttendanceRecapUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $recap = $this->route('attendanceRecap');

        return [
            'employee_id' => ['sometimes', 'required', 'integer', Rule::exists('hr_employees', 'id')],
            'period_year' => ['sometimes', 'required', 'integer', 'between:2000,2100'],
            'period_month' => [
                'sometimes', 'required', 'integer', 'between:1,12',
                Rule::unique('hr_attendance_recaps', 'period_month')
                    ->where(
                        fn ($query) => $query
                            ->where('employee_id', $this->integer('employee_id', (int) $recap?->employee_id))
                            ->where('period_year', $this->integer('period_year', (int) $recap?->period_year)),
                    )
                    ->ignore($recap?->id),
            ],
            'work_days' => ['sometimes', 'required', 'integer', 'between:0,31'],
            'present_days' => ['sometimes', 'required', 'integer', 'between:0,31'],
            'sick_days' => ['nullable', 'integer', 'between:0,31'],
            'leave_days' => ['nullable', 'integer', 'between:0,31'],
            'alpha_days' => ['nullable', 'integer', 'between:0,31'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $recap = $this->route('attendanceRecap');

            $accounted = $this->integer('present_days', (int) $recap?->present_days)
                + $this->integer('sick_days', (int) $recap?->sick_days)
                + $this->integer('leave_days', (int) $recap?->leave_days)
                + $this->integer('alpha_days', (int) $recap?->alpha_days);

            if ($accounted > $this->integer('work_days', (int) $recap?->work_days)) {
                $validator->errors()->add(
                    'present_days',
                    'Present + sick + leave + alpha days may not exceed the work days of the period.',
                );
            }
        });
    }
}
