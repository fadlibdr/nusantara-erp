<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route (inv.post)
    }

    public function rules(): array
    {
        return [
            // Same rule as Finance's DocumentCancelRequest, and for the same
            // reason: cancelling puts stock back on the shelf and reverses a
            // posted journal. "Salah" tells an auditor nothing a year later.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan terlalu singkat; jelaskan mengapa bon ini dibatalkan.',
            'reason.max' => 'Alasan pembatalan maksimal 500 karakter.',
        ];
    }
}
