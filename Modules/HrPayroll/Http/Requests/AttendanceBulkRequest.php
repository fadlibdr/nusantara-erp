<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\AttendanceStatus;

/**
 * One site, one date, many employees — the shape of the paper sheet a site
 * clerk actually fills, so the screen can post it in one request.
 */
class AttendanceBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // Attendance is recorded after people showed up. A future date is
            // a mistyped one, and it would sit invisible in every screen that
            // opens on today.
            'date' => ['required', 'date', 'before_or_equal:today'],
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.employee_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('hr_employees', 'id')->whereNull('deleted_at'),
            ],
            'entries.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'entries.*.note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'Tanggal absensi tidak boleh di masa depan.',
            'entries.*.employee_id.distinct' => 'Karyawan yang sama muncul dua kali dalam satu lembar.',
        ];
    }
}
