<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetentionReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'release_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            // Fills only to pass the masa-pemeliharaan time gate early; kept on
            // the release row as the audit trail's WHY.
            'override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
