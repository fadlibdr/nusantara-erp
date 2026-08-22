<?php

namespace Modules\HrPayroll\Http\Requests;

use Exception;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HrPayroll\Enums\EmploymentType;
use Modules\HrPayroll\Enums\PkwtBasis;
use Modules\HrPayroll\Enums\PtkpStatus;
use Modules\HrPayroll\Models\Employee;

class EmployeeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        // The rules for pkwt_end_date must hold on PARTIAL updates too. The
        // design's plain 'after:join_date' / 'prohibited_unless:employment_type'
        // read the request payload only, so a PUT carrying just the new end
        // date (join_date and employment_type omitted) would be refused for a
        // kontrak employee — the exact renewal edit this field exists for.
        // Anchor both rules on the payload when the field is sent, on the
        // stored row when it is not.
        $joinAnchor = $this->has('join_date')
            ? 'join_date'
            : $employee?->join_date?->toDateString();
        $effectiveType = $this->has('employment_type')
            ? $this->input('employment_type')
            : $employee?->employment_type?->value;
        $effectiveBasis = $this->has('pkwt_basis')
            ? $this->input('pkwt_basis')
            : $employee?->pkwt_basis?->value;

        // PP 35/2021 Pasal 8: jangka-waktu PKWT ≤ 5 tahun. Needs a literal
        // date (payload join if sent, else stored), and does not apply to a
        // selesainya-pekerjaan row, whose date is the Pasal 9 estimate.
        $pkwtCap = null;
        if ($effectiveBasis !== PkwtBasis::SelesainyaPekerjaan->value) {
            try {
                $join = $this->has('join_date')
                    ? Carbon::parse((string) $this->input('join_date'))
                    : $employee?->join_date;
                $pkwtCap = $join?->copy()->addYears(5)->toDateString();
            } catch (Exception) {
                // join_date's own 'date' rule reports the malformed value.
            }
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nik_ktp' => ['sometimes', 'required', 'digits:16', Rule::unique('hr_employees', 'nik_ktp')->ignore($employee?->id)],
            'npwp' => ['nullable', 'string', 'max:30'],
            'gender' => ['sometimes', 'required', Rule::in(['male', 'female'])],
            'birth_date' => ['sometimes', 'required', 'date', 'before:today'],
            'ptkp_status' => ['sometimes', 'required', Rule::enum(PtkpStatus::class)],
            'join_date' => ['sometimes', 'required', 'date'],
            'employment_type' => ['sometimes', 'required', Rule::enum(EmploymentType::class)],
            'pkwt_basis' => [
                'sometimes',
                'nullable',
                Rule::enum(PkwtBasis::class),
                Rule::prohibitedIf(fn (): bool => $effectiveType !== EmploymentType::Kontrak->value),
            ],
            'pkwt_end_date' => array_values(array_filter([
                'nullable',
                'date',
                $joinAnchor !== null ? 'after:'.$joinAnchor : null,
                Rule::prohibitedIf(fn (): bool => $effectiveType !== EmploymentType::Kontrak->value),
                $pkwtCap !== null ? 'before_or_equal:'.$pkwtCap : null,
            ])),
            'position' => ['sometimes', 'required', 'string', 'max:100'],
            'department' => ['sometimes', 'required', Rule::in(['proyek', 'engineering', 'keuangan', 'hrga', 'procurement', 'servis'])],
            'base_salary' => ['sometimes', 'required', 'numeric', 'min:0'],
            'fixed_allowances' => ['nullable', 'array'],
            'fixed_allowances.*' => ['numeric', 'min:0'],
            'bpjs_kesehatan_no' => ['nullable', 'string', 'max:30'],
            'bpjs_tk_no' => ['nullable', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account_no' => ['nullable', 'string', 'max:40'],
            'bank_account_name' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'resigned'])],
            'resign_date' => ['nullable', 'date', 'required_if:status,resigned'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // The reverse direction of the pkwt_end_date anchors above: a
                // PUT moving join_date ALONE says nothing about the stored
                // pkwt_end_date, so the field rules never run — yet the row as
                // it will be can claim a PKWT ending before it starts, or one
                // stretched past the 5-year cap. EMP-0007 with end 2026-12-31
                // must refuse join_date 2027-03-01.
                if (! $this->has('join_date') || $this->has('pkwt_end_date')) {
                    return;
                }

                if ($validator->errors()->has('join_date')) {
                    return; // a malformed date cannot be compared
                }

                /** @var Employee|null $employee */
                $employee = $this->route('employee');
                $storedEnd = $employee?->pkwt_end_date;

                if ($storedEnd === null) {
                    return;
                }

                // Leaving kontrak in the same payload clears the stored date
                // (EmployeeService), so there is no window left to defend.
                $effectiveType = $this->has('employment_type')
                    ? $this->input('employment_type')
                    : $employee?->employment_type?->value;

                if ($effectiveType !== EmploymentType::Kontrak->value) {
                    return;
                }

                $newJoin = Carbon::parse((string) $this->input('join_date'));

                if ($storedEnd->lte($newJoin)) {
                    $validator->errors()->add('join_date', 'Tanggal akhir PKWT harus setelah tanggal masuk.');

                    return;
                }

                $effectiveBasis = $this->has('pkwt_basis')
                    ? $this->input('pkwt_basis')
                    : $employee?->pkwt_basis?->value;

                if ($effectiveBasis !== PkwtBasis::SelesainyaPekerjaan->value
                    && $storedEnd->gt($newJoin->copy()->addYears(5))) {
                    $validator->errors()->add('join_date', 'Jangka waktu PKWT maksimal 5 tahun sejak tanggal masuk (PP 35/2021 Pasal 8).');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'pkwt_basis.prohibited' => 'Dasar PKWT hanya untuk karyawan kontrak (PKWT).',
            'pkwt_end_date.prohibited' => 'Tanggal akhir PKWT hanya untuk karyawan kontrak (PKWT).',
            'pkwt_end_date.after' => 'Tanggal akhir PKWT harus setelah tanggal masuk.',
            'pkwt_end_date.before_or_equal' => 'Jangka waktu PKWT maksimal 5 tahun sejak tanggal masuk (PP 35/2021 Pasal 8).',
        ];
    }
}
