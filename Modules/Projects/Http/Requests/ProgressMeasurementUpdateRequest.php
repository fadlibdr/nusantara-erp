<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProgressMeasurementUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * project_id and contract_id are absent on purpose: an opname belongs to
     * the contract it measures, and moving it would silently re-base every
     * qty_prev it carries against a different approved history.
     */
    public function rules(): array
    {
        return [
            'period_start' => ['sometimes', 'string', 'date'],
            'period_end' => ['sometimes', 'string', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],

            // Lines are replaced wholesale when present (CONVENTIONS §6).
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.boq_item_id' => ['required_with:items', 'integer', Rule::exists('est_boq_items', 'id')],
            'items.*.location_id' => ['nullable', 'integer', Rule::exists('core_locations', 'id')],
            'items.*.qty_this' => ['required_with:items', 'numeric'],
            'items.*.notes' => ['nullable', 'string', 'max:300'],
        ];
    }
}
