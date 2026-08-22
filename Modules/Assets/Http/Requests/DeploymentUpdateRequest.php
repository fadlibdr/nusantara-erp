<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeploymentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        // Asset, project and start date are fixed once mobilized; the rest is editable.
        return [
            'planned_until' => ['nullable', 'date'],
            'daily_rate_internal' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
