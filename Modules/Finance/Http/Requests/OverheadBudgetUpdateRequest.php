<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OverheadBudgetUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            /*
             * Rentang tahun ditulis, bukan dibiarkan bebas: sebuah OVB untuk
             * tahun 202 atau 20265 adalah salah ketik yang baru ketahuan saat
             * layar realisasi memindai tahun yang tidak ada isinya.
             */
            'period_year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'notes' => ['nullable', 'string'],
            'lines' => ['sometimes', 'array'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('fin_accounts', 'id')],
            // Nol diperbolehkan (akun yang sengaja dianggarkan nol), negatif
            // tidak: sebuah anggaran negatif tidak punya arti yang bisa dibaca
            // pada layar "berapa persen terpakai".
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
