<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArInvoiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string', 'max:500'],
            'dpp' => ['sometimes', 'numeric', 'min:0.01'],
            'ppn_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'retention_withheld' => ['sometimes', 'numeric', 'min:0'],
            // P3 — see ArInvoiceStoreRequest for why the reason's requirement
            // lives in the service and not in the rule set.
            'penalty_amount' => ['sometimes', 'numeric', 'min:0'],
            'penalty_reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }
}
