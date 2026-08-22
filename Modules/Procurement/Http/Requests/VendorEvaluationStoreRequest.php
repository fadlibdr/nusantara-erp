<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorEvaluationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')],
            'project_id' => ['nullable', 'integer'],
            'evaluated_by' => ['nullable', 'integer'],
            'period' => ['required', 'string', 'max:20'],
            'quality_score' => ['required', 'integer', 'min:1', 'max:5'],
            // Boleh kosong: VendorEvaluationService mengisinya dari riwayat
            // GRN vs tanggal janji PO — dan menolak (422) bila vendor belum
            // punya riwayat, supaya kosong tidak pernah jadi skor karangan.
            'delivery_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'price_score' => ['required', 'integer', 'min:1', 'max:5'],
            'service_score' => ['required', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
