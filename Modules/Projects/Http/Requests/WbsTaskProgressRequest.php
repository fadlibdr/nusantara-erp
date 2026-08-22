<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WbsTaskProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'progress_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'actual_start' => ['nullable', 'date'],
            'actual_end' => ['nullable', 'date', 'after_or_equal:actual_start'],
        ];
    }
}
