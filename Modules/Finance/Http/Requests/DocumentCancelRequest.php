<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route (fin.post)
    }

    public function rules(): array
    {
        return [
            // Cancelling reverses a posted journal. "Salah" tells an auditor
            // nothing a year later, so the reason is required and has to be a
            // sentence rather than a keystroke.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan terlalu singkat; jelaskan mengapa dokumen ini dibatalkan.',
            'reason.max' => 'Alasan pembatalan maksimal 500 karakter.',
        ];
    }
}
