<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MethodLibraryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:60'],
            'work_package' => ['required', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:250'],
            'summary' => ['nullable', 'string'],
            'effective_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
