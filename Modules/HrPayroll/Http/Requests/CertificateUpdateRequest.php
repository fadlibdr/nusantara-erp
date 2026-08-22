<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HrPayroll\Enums\CertificateType;
use Modules\HrPayroll\Models\Certificate;

class CertificateUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'required', 'integer', Rule::exists('hr_employees', 'id')->whereNull('deleted_at')],
            'certificate_type' => ['sometimes', 'required', Rule::enum(CertificateType::class)],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'number' => ['nullable', 'string', 'max:100'],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issued_date' => ['nullable', 'date'],
            // The issued/expiry window is enforced in after(), not here: a
            // one-way 'after:' anchor misses the PUT that carries issued_date
            // alone and slides it past the stored expiry.
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Partial update: the window must hold on the ROW AS IT WILL
                // BE, with the stored value filling whichever side the payload
                // leaves out. A renewal (PATCH expiry alone, issued 2024-06-01
                // stays put) and a correction (PATCH issued alone) both land
                // here; a payload touching neither date has nothing to prove.
                if (! $this->has('issued_date') && ! $this->has('expiry_date')) {
                    return;
                }

                if ($validator->errors()->hasAny(['issued_date', 'expiry_date'])) {
                    return; // a malformed date cannot be compared
                }

                /** @var Certificate|null $certificate */
                $certificate = $this->route('certificate');

                $issued = $this->has('issued_date')
                    ? ($this->filled('issued_date') ? Carbon::parse((string) $this->input('issued_date')) : null)
                    : $certificate?->issued_date;
                $expiry = $this->has('expiry_date')
                    ? ($this->filled('expiry_date') ? Carbon::parse((string) $this->input('expiry_date')) : null)
                    : $certificate?->expiry_date;

                if ($issued !== null && $expiry !== null && $expiry->lte($issued)) {
                    $validator->errors()->add(
                        $this->has('expiry_date') ? 'expiry_date' : 'issued_date',
                        'Tanggal kedaluwarsa harus setelah tanggal terbit.',
                    );
                }
            },
        ];
    }
}
