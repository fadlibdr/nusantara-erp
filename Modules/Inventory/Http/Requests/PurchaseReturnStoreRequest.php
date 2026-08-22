<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReturnStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'goods_receipt_id' => ['required', 'integer', Rule::exists('inv_goods_receipts', 'id')->whereNull('deleted_at')],
            'return_date' => ['required', 'date'],
            // Mandatory for the same reason a cancellation demands one: goods
            // going back to a vendor are a dispute in the making, and the
            // document is the evidence.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            // Line ownership (the referenced line belongs to THIS receipt) is
            // the service's check — it needs the resolved rows, not bare ids.
            // distinct because the posting ceiling is asked PER receipt line:
            // two lines naming the same one each fit alone under it, together
            // they return more than the vendor delivered.
            'items.*.grn_item_id' => ['required', 'integer', 'distinct', Rule::exists('inv_goods_receipt_items', 'id')],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
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
