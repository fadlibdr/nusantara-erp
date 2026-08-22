<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\CertificateType;

class CertificateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')->whereNull('deleted_at')],
            'certificate_type' => ['required', Rule::enum(CertificateType::class)],
            'name' => ['required', 'string', 'max:160'],
            'number' => ['nullable', 'string', 'max:100'],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issued_date' => ['nullable', 'date'],
            // 'after:issued_date' only when issued_date is actually in the
            // payload: against an absent field the comparison degenerates into
            // strtotime('issued_date') and the outcome is a coin flip.
            'expiry_date' => array_values(array_filter([
                'nullable',
                'date',
                $this->filled('issued_date') ? 'after:issued_date' : null,
            ])),
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'expiry_date.after' => 'Tanggal kedaluwarsa harus setelah tanggal terbit.',
        ];
    }
}
