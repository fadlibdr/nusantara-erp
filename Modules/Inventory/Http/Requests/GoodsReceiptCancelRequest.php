<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route (inv.post)
    }

    public function rules(): array
    {
        return [
            // Same rule as IssueCancelRequest, and for the same reason:
            // cancelling walks stock back out of the gudang, reverses a posted
            // journal and reopens a purchase order. "Salah" tells an auditor
            // nothing a year later.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.min' => 'Alasan pembatalan terlalu singkat; jelaskan mengapa penerimaan ini dibatalkan.',
            'reason.max' => 'Alasan pembatalan maksimal 500 karakter.',
        ];
    }
}
