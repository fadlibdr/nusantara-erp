<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvmReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'as_of' => ['nullable', 'date', 'before_or_equal:today'],
            'baseline_id' => ['nullable', 'integer', Rule::exists('prj_baselines', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'as_of.date' => 'Tanggal laporan tidak valid.',
            'as_of.before_or_equal' => 'Tanggal laporan tidak boleh di masa depan — laporan masa depan '
                .'membandingkan rencana yang belum jatuh tempo dengan progres hari ini dan mengarang keterlambatan.',
            'baseline_id.exists' => 'Baseline tidak ditemukan.',
        ];
    }
}
