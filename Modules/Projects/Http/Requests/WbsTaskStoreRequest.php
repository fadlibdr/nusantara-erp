<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WbsTaskStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('prj_wbs_tasks', 'id')
                    ->where('project_id', $this->route('project')?->id),
            ],
            'boq_item_id' => ['nullable', 'integer', 'min:1'],
            'wbs_code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:500'],
            'weight_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
