<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialVarianceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'as_of' => ['nullable', 'date', 'before_or_equal:today'],
            'basis' => ['nullable', Rule::in(['progress', 'full'])],
        ];
    }

    public function messages(): array
    {
        return [
            'as_of.date' => 'Tanggal laporan tidak valid.',
            'as_of.before_or_equal' => 'Tanggal laporan tidak boleh di masa depan — bon yang ada hari ini '
                .'akan dibandingkan dengan teori pekerjaan yang belum jatuh tempo.',
            'basis.in' => "Dasar perhitungan tidak dikenal — pilih 'progress' (teori sampai progres paket) "
                ."atau 'full' (volume kontrak penuh).",
        ];
    }
}
