<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')],
            'purchase_requisition_id' => ['nullable', 'integer', Rule::exists('prc_purchase_requisitions', 'id')],
            'project_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'order_date' => ['required', 'date'],
            // Wajib, bukan nullable: expected_date adalah kolom yang dibaca
            // pengawas tenggat `po_expected` (WatchedDeadlines). Diukur 4 Sep
            // 2026 di produksi (ANALISIS-PROSES D1): PO/2026/III/0002 Rp 128 jt
            // disetujui 40 hari, 0 GRN, dan tidak pernah disebut pengawas —
            // tanggalnya NULL karena formulir tidak pernah memintanya (T3.5).
            'expected_date' => ['required', 'date', 'after_or_equal:order_date'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            // Alasan menembus gate prakualifikasi vendor; ikut tersimpan di PO
            // sebagai jejak audit (temuan #35).
            'qualification_override_reason' => ['nullable', 'string', 'max:500'],
            // PO tanpa PR boleh (darurat lapangan), tetapi harus beralasan —
            // pola yang sama dengan override prakualifikasi di atas. Diukur
            // 4 Sep 2026 di produksi (ANALISIS-PROSES E3): PO/2026/III/0002
            // Rp 128 jt ber-purchase_requisition_id NULL tanpa alasan tercatat
            // di mana pun selain komentar seeder. Kalimat 422-nya dari lang:
            // "Alasan tanpa PR wajib diisi bila PR kosong." (T3.8).
            'pr_bypass_reason' => ['required_without:purchase_requisition_id', 'nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
