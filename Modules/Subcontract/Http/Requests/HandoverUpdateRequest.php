<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\HandoverType;

class HandoverUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** subcontract_id is fixed at creation — a BAST belongs to its SPK. */
    public function rules(): array
    {
        return [
            'handover_type' => ['sometimes', Rule::enum(HandoverType::class)],
            'handover_date' => ['sometimes', 'string', 'date'],
            'retention_release_due' => ['sometimes', 'nullable', 'string', 'date'],
            'scope_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'handed_over_by' => ['sometimes', 'nullable', 'string', 'max:150'],
            'received_by' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }
}
