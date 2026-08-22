<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Projects\Services\BastPrerequisiteService;

class BastApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            // Lifts WARNINGS on BAST II only, never a hard block. The minimum
            // length is the whole point: "ok" is not a reason for releasing
            // Rp 2.425.000.000 of a customer's security early.
            'override_reason' => [
                'nullable',
                'string',
                'min:'.BastPrerequisiteService::MIN_OVERRIDE_REASON_LENGTH,
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'override_reason.min' => 'Alasan melewati prasyarat harus dijelaskan, minimal '
                .BastPrerequisiteService::MIN_OVERRIDE_REASON_LENGTH.' karakter.',
        ];
    }
}
