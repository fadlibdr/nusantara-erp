<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Enums\GuaranteeType;

class GuaranteeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'guarantee_type' => ['required', Rule::enum(GuaranteeType::class)],
            // The DB unique index on (issuer, number) includes soft-deleted
            // rows, so the check here must too — otherwise a re-recorded
            // number 422s in one case and 500s in the other.
            'number' => [
                'required', 'string', 'max:100',
                Rule::unique('crm_guarantees', 'number')
                    ->where('issuer', (string) $this->input('issuer')),
            ],
            'issuer' => ['required', 'string', 'max:160'],
            // A bid bond exists before any contract does, so either anchor
            // will do — but a guarantee attached to nothing is unfindable
            // exactly when it matters (audit, klaim, pengembalian). The
            // whereNull is part of the same guard: both anchors soft-delete,
            // and a trashed anchor resolves to null through the global scope —
            // QTN/2026/VII/0005 (deleted 2026-07-26) would otherwise satisfy
            // Rule::exists while no screen can ever reach the bond again.
            'contract_id' => ['nullable', 'required_without:quotation_id', 'integer', Rule::exists('crm_contracts', 'id')->whereNull('deleted_at')],
            'quotation_id' => ['nullable', 'required_without:contract_id', 'integer', Rule::exists('crm_quotations', 'id')->whereNull('deleted_at')],
            'value' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::enum(GuaranteeStatus::class)],
            'document_location' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
