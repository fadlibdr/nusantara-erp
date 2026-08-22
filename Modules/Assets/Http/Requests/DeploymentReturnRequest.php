<?php

namespace Modules\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeploymentReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'returned_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
