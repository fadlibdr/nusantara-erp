<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransmittalReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'received_by' => ['required', 'string', 'max:150'],
            'received_at' => ['nullable', 'string', 'date'],
        ];
    }
}
