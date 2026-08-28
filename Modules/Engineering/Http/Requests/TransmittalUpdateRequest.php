<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\TransmittalDirection;

class TransmittalUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::enum(TransmittalDirection::class)],
            'to_party' => ['sometimes', 'string', 'max:200'],
            'transmittal_date' => ['sometimes', 'string', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.kind' => ['required_with:lines', 'string', 'max:40'],
            'lines.*.document_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
            'lines.*.remarks' => ['nullable', 'string', 'max:200'],
        ];
    }
}
