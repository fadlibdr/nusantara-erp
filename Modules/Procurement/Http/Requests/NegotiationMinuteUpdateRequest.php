<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NegotiationMinuteUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // rfq_id/vendor_id sengaja tidak di sini: keduanya identitas risalah dan
        // tidak boleh berpindah lewat Ubah (diabaikan di NegotiationMinuteService).
        return [
            'meeting_date' => ['sometimes', 'date'],
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
