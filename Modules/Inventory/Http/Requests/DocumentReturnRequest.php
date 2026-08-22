<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The one-click "Buat Retur" off a bon's or a GRN's detail screen: the dialog
 * collects only the reason (and optionally a date); the lines are computed
 * server-side from what remains returnable.
 */
class DocumentReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route (inv.create)
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'return_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan retur wajib diisi.',
            'reason.min' => 'Alasan retur terlalu singkat; jelaskan mengapa barang ini kembali.',
            'reason.max' => 'Alasan retur maksimal 500 karakter.',
        ];
    }
}
