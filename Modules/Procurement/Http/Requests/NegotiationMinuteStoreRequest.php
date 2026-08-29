<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NegotiationMinuteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rfq_id' => ['required', 'integer', Rule::exists('prc_rfqs', 'id')->whereNull('deleted_at')],
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')->whereNull('deleted_at')],
            'meeting_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:200'],
            'peserta' => ['nullable', 'array'],
            'peserta.*.nama' => ['required_with:peserta', 'string', 'max:120'],
            'peserta.*.jabatan' => ['nullable', 'string', 'max:120'],
            'peserta.*.pihak' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.rfq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.harga_awal' => ['nullable', 'numeric', 'min:0'],
            'items.*.harga_nego' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
