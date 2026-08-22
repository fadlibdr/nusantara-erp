<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketActivityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'activity_type' => ['required', Rule::in(['comment', 'status_change', 'assignment', 'work_log'])],
            'body' => ['required', 'string', 'max:10000'],
            'minutes_spent' => ['nullable', 'integer', 'min:1', 'max:14400'],
        ];
    }
}
