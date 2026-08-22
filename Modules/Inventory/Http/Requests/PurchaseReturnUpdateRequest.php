<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReturnUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // goods_receipt_id is deliberately NOT a rule — validated() only
            // returns validated keys, so a payload id never reaches
            // PurchaseReturnService::update. A return re-pointed at another
            // receipt would reverse a clearing that receipt never recorded;
            // wrong receipt means delete the draft and raise it again.
            'return_date' => ['sometimes', 'required', 'date'],
            'reason' => ['sometimes', 'required', 'string', 'min:5', 'max:500'],
            'items' => ['sometimes', 'array', 'min:1'],
            // distinct for the same reason as the store request: the posting
            // ceiling is per receipt line, and a duplicated line walks past it.
            'items.*.grn_item_id' => ['required_with:items', 'integer', 'distinct', Rule::exists('inv_goods_receipt_items', 'id')],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan retur wajib diisi.',
            'reason.min' => 'Alasan retur terlalu singkat; jelaskan mengapa barang ini dikembalikan.',
            'reason.max' => 'Alasan retur maksimal 500 karakter.',
            'items.*.grn_item_id.distinct' => 'Baris penerimaan yang sama tidak boleh muncul dua kali dalam satu retur; gabungkan kuantitasnya.',
        ];
    }
}
