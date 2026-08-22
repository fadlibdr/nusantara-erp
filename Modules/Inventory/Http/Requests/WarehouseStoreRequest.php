<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('inv_warehouses', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer'], // cross-module: site warehouse when set
            'address' => ['nullable', 'string'],
            'keeper_employee_id' => ['nullable', 'integer'], // cross-module: hr_employees.id
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
