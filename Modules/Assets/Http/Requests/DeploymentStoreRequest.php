<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeploymentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', Rule::exists('ast_assets', 'id')],
            'project_id' => ['required', 'integer'], // cross-module: prj_projects.id
            'deployed_from' => ['required', 'date'],
            'planned_until' => ['nullable', 'date', 'after_or_equal:deployed_from'],
            'daily_rate_internal' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
