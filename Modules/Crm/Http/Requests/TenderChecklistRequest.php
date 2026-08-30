<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenderChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The key allow-list lives in TenderPackageService, against the config
        // template — one place, so a seeder cannot store an item the screen
        // could not.
        return [
            'checklist' => ['present', 'array'],
            'checklist.*.key' => ['required', 'string', 'max:60'],
            'checklist.*.checked' => ['nullable', 'boolean'],
            'checklist.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
