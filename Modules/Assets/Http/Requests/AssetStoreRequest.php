<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', Rule::exists('ast_categories', 'id')],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'acquisition_date' => ['required', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'min:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'lte:acquisition_cost'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:600'],
            'depreciation_start_date' => ['nullable', 'date'],
            'custodian_employee_id' => ['nullable', 'integer'], // cross-module: hr_employees.id
            'warehouse_id' => ['nullable', 'integer'], // cross-module: inv_warehouses.id
            'notes' => ['nullable', 'string'],
        ];
    }
}
