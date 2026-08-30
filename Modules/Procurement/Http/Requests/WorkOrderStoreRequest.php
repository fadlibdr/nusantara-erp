<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Enums\RateBasis;

class WorkOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')],
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'qualification_override_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.asset_id' => ['nullable', 'integer', Rule::exists('ast_assets', 'id')],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.rate_basis' => ['required', Rule::enum(RateBasis::class)],
            'items.*.rate' => ['required', 'numeric', 'min:0.01'],
            'items.*.qty_periods' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
