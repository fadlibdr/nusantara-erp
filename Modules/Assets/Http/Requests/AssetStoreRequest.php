<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Enums\AssetOwnership;
use Modules\Assets\Enums\RateBasis;

/**
 * P5: dua bentuk aset, satu register. OWNED membawa kolom perolehan seperti
 * sebelum P5 (wajib semua); RENTED membawa kolom sewa dan MENOLAK kolom
 * perolehan — harga perolehan pada alat sewa adalah angka karangan, dan
 * prohibited_if di sini adalah pintu yang menjaga NULL-nya tetap NULL sejak
 * input, bukan hanya di model.
 */
class AssetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /** Payload pra-P5 tanpa field ownership tetap berarti aset beli. */
    protected function prepareForValidation(): void
    {
        if (! $this->has('ownership')) {
            $this->merge(['ownership' => AssetOwnership::Owned->value]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', Rule::exists('ast_categories', 'id')],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'ownership' => ['required', Rule::enum(AssetOwnership::class)],

            // Kolom perolehan — milik aset beli saja.
            'acquisition_date' => ['required_if:ownership,owned', 'prohibited_if:ownership,rented', 'nullable', 'date'],
            'acquisition_cost' => ['required_if:ownership,owned', 'prohibited_if:ownership,rented', 'nullable', 'numeric', 'min:0'],
            'salvage_value' => ['nullable', 'prohibited_if:ownership,rented', 'numeric', 'min:0', 'lte:acquisition_cost'],
            'useful_life_months' => ['required_if:ownership,owned', 'prohibited_if:ownership,rented', 'nullable', 'integer', 'min:1', 'max:600'],
            'depreciation_start_date' => ['nullable', 'prohibited_if:ownership,rented', 'date'],

            // Kolom sewa — milik alat sewa saja. vendor_id lintas-modul ke
            // prc_vendors (lessor); tipe vendornya tidak dipagari di master
            // aset — gerbang komitmen uangnya ada di PPK (WorkOrderService).
            'vendor_id' => ['required_if:ownership,rented', 'prohibited_if:ownership,owned', 'nullable', 'integer', Rule::exists('prc_vendors', 'id')],
            'rental_rate' => ['required_if:ownership,rented', 'prohibited_if:ownership,owned', 'nullable', 'numeric', 'min:0.01'],
            'rate_basis' => ['required_if:ownership,rented', 'prohibited_if:ownership,owned', 'nullable', Rule::enum(RateBasis::class)],
            'rental_start' => ['nullable', 'prohibited_if:ownership,owned', 'date'],
            'rental_end' => ['nullable', 'prohibited_if:ownership,owned', 'date', 'after_or_equal:rental_start'],

            'custodian_employee_id' => ['nullable', 'integer'], // cross-module: hr_employees.id
            'warehouse_id' => ['nullable', 'integer'], // cross-module: inv_warehouses.id
            'notes' => ['nullable', 'string'],
        ];
    }
}
