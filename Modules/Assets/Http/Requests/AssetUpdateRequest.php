<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Enums\RateBasis;

class AssetUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'category_id' => ['sometimes', 'integer', Rule::exists('ast_categories', 'id')],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'acquisition_date' => ['sometimes', 'date'],
            'acquisition_cost' => ['sometimes', 'numeric', 'min:0'],
            'salvage_value' => ['sometimes', 'numeric', 'min:0'],
            'useful_life_months' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'depreciation_start_date' => ['nullable', 'date'],
            // P5 — kolom sewa (hanya bermakna pada aset rented; guard bentuk
            // vs kepemilikan berjalan di AssetRegisterService::update, yang
            // membaca ulang barisnya di dalam transaksi). ownership sendiri
            // SENGAJA tidak bisa diubah lewat update: beli-putus alat sewa
            // (kapitalisasi) adalah peristiwa akuntansi, bukan suntingan
            // register.
            'vendor_id' => ['nullable', 'integer', Rule::exists('prc_vendors', 'id')],
            'rental_rate' => ['sometimes', 'numeric', 'min:0.01'],
            'rate_basis' => ['sometimes', Rule::enum(RateBasis::class)],
            'rental_start' => ['nullable', 'date'],
            'rental_end' => ['nullable', 'date', 'after_or_equal:rental_start'],
            'custodian_employee_id' => ['nullable', 'integer'], // cross-module: hr_employees.id
            'warehouse_id' => ['nullable', 'integer'], // cross-module: inv_warehouses.id
            // deployed is reachable only through the deploy/return flow, and
            // disposed only through POST assets/{id}/dispose. Accepting
            // "disposed" here was the side door: the asset left the register
            // with no derecognition journal, so cost and accumulated
            // depreciation stayed on the balance sheet forever. The disposal
            // fields left with it — editing them freehand would desync the
            // register from the disposal journal already posted.
            'status' => ['sometimes', Rule::in(['available', 'maintenance'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
