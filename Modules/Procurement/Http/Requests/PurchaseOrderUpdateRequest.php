<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['sometimes', 'integer', Rule::exists('prc_vendors', 'id')],
            'purchase_requisition_id' => ['nullable', 'integer', Rule::exists('prc_purchase_requisitions', 'id')],
            'project_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'order_date' => ['sometimes', 'date'],
            // Ubah memakai formulir yang sama dengan tanda wajib yang sama;
            // draf yang diedit sampai tanggalnya kosong akan lepas lagi dari
            // pengawas `po_expected` (ANALISIS-PROSES D1, T3.5). `sometimes`:
            // PUT tanpa kunci ini (edit baris saja) tetap boleh.
            'expected_date' => ['sometimes', 'required', 'date'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            // Ubah memakai formulir yang sama dengan tanda wajib yang sama
            // (preseden T3.5). Wajib HANYA bila permintaan ini menyentuh
            // purchase_requisition_id dan mengosongkannya — PUT baris saja
            // (tanpa kunci PR) tidak boleh menuntut alasan yang sudah tersimpan,
            // sedangkan melepas PR lewat API tanpa alasan meninggalkan PO
            // langsung tanpa jejak, dan itulah yang ditolak (T3.8).
            'pr_bypass_reason' => [
                Rule::when($this->has('purchase_requisition_id'), ['required_without:purchase_requisition_id']),
                'nullable', 'string', 'max:500',
            ],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
