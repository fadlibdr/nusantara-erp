<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RfqQuotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'quotes' => ['required', 'array', 'min:1'],
            'quotes.*.rfq_item_id' => ['required', 'integer'],
            'quotes.*.vendor_id' => ['required', 'integer'],
            // Nol sah: vendor bisa menawarkan gratis-ongkos pada baris jasa
            // kirim; keanggotaan baris & undangan vendor diperiksa RfqService
            // dengan pesan yang menyebut namanya, bukan lewat exists di sini.
            'quotes.*.unit_price' => ['required', 'numeric', 'min:0'],
            'quotes.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
