<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Assets\Enums\RateBasis;

class WorkOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['sometimes', 'integer', Rule::exists('prc_vendors', 'id')],
            'project_id' => ['sometimes', 'integer', Rule::exists('prj_projects', 'id')],
            'title' => ['sometimes', 'string', 'max:200'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            // Baris diganti seutuhnya bila dikirim (konvensi §6).
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.asset_id' => ['nullable', 'integer', Rule::exists('ast_assets', 'id')],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.rate_basis' => ['required_with:items', Rule::enum(RateBasis::class)],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.qty_periods' => ['required_with:items', 'numeric', 'min:0.001'],
        ];
    }
}
