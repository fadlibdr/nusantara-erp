<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RfqStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'purchase_requisition_id' => ['nullable', 'integer', Rule::exists('prc_purchase_requisitions', 'id')],
            'project_id' => ['nullable', 'integer'],
            'rfq_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:rfq_date'],
            'notes' => ['nullable', 'string'],
            // Minimal satu undangan: lembar banding tanpa vendor bukan banding.
            'vendor_ids' => ['required', 'array', 'min:1'],
            'vendor_ids.*' => ['integer', Rule::exists('prc_vendors', 'id')->whereNull('deleted_at')],
            // Baris eksplisit hanya untuk RFQ mandiri; RFQ dari PR menyalin
            // baris PR-nya dan mengabaikan daftar ini (RfqService). Tanpa
            // min:1 di sini: form generik tetap mengirim items:[] saat RFQ
            // dibuat dari PR, dan [] yang "hadir" akan gagal min:1 padahal
            // barisnya sah datang dari PR — RFQ mandiri kosong tetap ditolak
            // required_without dan penjaga di RfqService.
            'items' => ['required_without:purchase_requisition_id', 'array'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
        ];
    }
}
