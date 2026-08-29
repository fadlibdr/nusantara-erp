<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AwardDecisionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // rfq_id/vendor_id immutable (diabaikan di AwardDecisionService::update).
        return [
            'rab_amount' => ['sometimes', 'numeric', 'min:0'],
            'awarded_amount' => ['sometimes', 'numeric', 'min:0'],
            'deviation_reason' => ['nullable', 'string', 'max:500'],
            'committee' => ['nullable', 'array'],
            'committee.*.nama' => ['required_with:committee', 'string', 'max:120'],
            'committee.*.jabatan' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
