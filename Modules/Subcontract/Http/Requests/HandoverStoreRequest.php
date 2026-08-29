<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\HandoverType;

class HandoverStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Per-field shape only. The two prerequisites — the last opname approved,
     * the retention still held — weigh several rows against each other and live
     * in HandoverService, where they run at approval.
     */
    public function rules(): array
    {
        return [
            'subcontract_id' => ['required', 'integer', Rule::exists('scm_subcontracts', 'id')],
            'handover_type' => ['required', Rule::enum(HandoverType::class)],
            'handover_date' => ['required', 'string', 'date'],
            // Optional: BAST I copies the SPK's defect_liability_until when the
            // caller sends nothing, and leaves it blank when the SPK has none.
            'retention_release_due' => ['nullable', 'string', 'date'],
            'scope_notes' => ['nullable', 'string', 'max:2000'],
            'handed_over_by' => ['nullable', 'string', 'max:150'],
            'received_by' => ['nullable', 'string', 'max:150'],
        ];
    }
}
