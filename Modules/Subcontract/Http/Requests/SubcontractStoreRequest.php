<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\PphConstructionScheme;

class SubcontractStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer'], // cross-module; subcontractor check in the service
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'scope' => ['nullable', 'string'],
            'retention_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph_scheme' => ['required', Rule::enum(PphConstructionScheme::class)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Akhir masa pemeliharaan — the retention time gate reads this.
            'defect_liability_until' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            // Alasan menembus gate prakualifikasi vendor (temuan #35); ikut
            // tersimpan di SPK sebagai jejak audit saat override dipakai.
            'qualification_override_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.wbs_code' => ['nullable', 'string', 'max:20'],
            'items.*.description' => ['required_without:items.*.boq_item_id', 'nullable', 'string', 'max:500'],
            'items.*.qty' => ['required_without:items.*.boq_item_id', 'nullable', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
