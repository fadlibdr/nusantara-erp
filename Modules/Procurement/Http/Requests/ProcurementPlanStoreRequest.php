<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\ProcurementMethod;

class ProcurementPlanStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $methods = array_map(static fn (ProcurementMethod $m): string => $m->value, ProcurementMethod::cases());

        return [
            'project_id' => ['nullable', 'integer'],
            'cost_budget_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'closed'])],
            'items' => ['nullable', 'array'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.package' => ['required_with:items', 'string', 'max:200'],
            'items.*.method' => ['nullable', Rule::in($methods)],
            'items.*.estimated_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.target_contract_date' => ['nullable', 'date'],
            'items.*.pic' => ['nullable', 'string', 'max:120'],
            'items.*.status' => ['nullable', Rule::in(['planned', 'in_progress', 'contracted', 'cancelled'])],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
