<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Enums\MaintenanceType;

class MaintenanceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', Rule::exists('ast_assets', 'id')],
            'maintenance_date' => ['required', 'date'],
            'maintenance_type' => ['required', Rule::enum(MaintenanceType::class)],
            'vendor_id' => ['nullable', 'integer'], // cross-module: prc_vendors.id
            'cost' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'next_due_date' => ['nullable', 'date', 'after:maintenance_date'],
        ];
    }
}
