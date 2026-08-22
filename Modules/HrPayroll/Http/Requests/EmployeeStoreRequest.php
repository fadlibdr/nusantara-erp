<?php

namespace Modules\HrPayroll\Http\Requests;

use Exception;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\EmploymentType;
use Modules\HrPayroll\Enums\PkwtBasis;
use Modules\HrPayroll\Enums\PtkpStatus;

class EmployeeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        // PP 35/2021 Pasal 8: a jangka-waktu PKWT (including perpanjangan)
        // tops out at 5 tahun — join 2026-08-01 with end 2035-01-01 is already
        // PKWTT demi hukum, and recording it would make the watcher count down
        // 8+ years to a legally meaningless date. A selesainya-pekerjaan row
        // is exempt: its date is the Pasal 9 completion ESTIMATE, which may
        // lawfully run longer.
        $pkwtCap = null;
        if ($this->filled('join_date') && $this->input('pkwt_basis') !== PkwtBasis::SelesainyaPekerjaan->value) {
            try {
                $pkwtCap = Carbon::parse((string) $this->input('join_date'))->addYears(5)->toDateString();
            } catch (Exception) {
                // join_date's own 'date' rule reports the malformed value.
            }
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'nik_ktp' => ['required', 'digits:16', Rule::unique('hr_employees', 'nik_ktp')],
            'npwp' => ['nullable', 'string', 'max:30'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'birth_date' => ['required', 'date', 'before:today'],
            'ptkp_status' => ['required', Rule::enum(PtkpStatus::class)],
            'join_date' => ['required', 'date'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            // Which PP 35/2021 shape the PKWT is. NULL (the legacy state) is
            // read as jangka_waktu, so the watcher keeps nagging for a date
            // until HR explicitly records selesainya_pekerjaan.
            'pkwt_basis' => ['nullable', Rule::enum(PkwtBasis::class), 'prohibited_unless:employment_type,kontrak'],
            // Not hard-required for kontrak: legacy rows have no date and HR
            // must not be forced to invent one — the deadline watcher alarms on
            // the gap instead. Prohibited elsewhere so a tetap/harian row can
            // never carry a phantom PKWT clock.
            'pkwt_end_date' => array_values(array_filter([
                'nullable',
                'date',
                'after:join_date',
                'prohibited_unless:employment_type,kontrak',
                $pkwtCap !== null ? 'before_or_equal:'.$pkwtCap : null,
            ])),
            'position' => ['required', 'string', 'max:100'],
            'department' => ['required', Rule::in(['proyek', 'engineering', 'keuangan', 'hrga', 'procurement', 'servis'])],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'fixed_allowances' => ['nullable', 'array'],
            'fixed_allowances.*' => ['numeric', 'min:0'],
            'bpjs_kesehatan_no' => ['nullable', 'string', 'max:30'],
            'bpjs_tk_no' => ['nullable', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account_no' => ['nullable', 'string', 'max:40'],
            'bank_account_name' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'resigned'])],
            'resign_date' => ['nullable', 'date', 'required_if:status,resigned', 'after_or_equal:join_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'pkwt_basis.prohibited_unless' => 'Dasar PKWT hanya untuk karyawan kontrak (PKWT).',
            'pkwt_end_date.prohibited_unless' => 'Tanggal akhir PKWT hanya untuk karyawan kontrak (PKWT).',
            'pkwt_end_date.after' => 'Tanggal akhir PKWT harus setelah tanggal masuk.',
            'pkwt_end_date.before_or_equal' => 'Jangka waktu PKWT maksimal 5 tahun sejak tanggal masuk (PP 35/2021 Pasal 8).',
        ];
    }
}
