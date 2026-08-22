<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\LeaveType;

class LeaveRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')->whereNull('deleted_at')],
            'leave_type' => ['required', Rule::enum(LeaveType::class)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // Required for every type: cuti tahunan states the occasion, sakit
            // points at the surat dokter in the attachments, izin/khusus name
            // the statutory ground (menikah, kematian, …) the approver rules on.
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh mendahului tanggal mulai.',
        ];
    }
}
