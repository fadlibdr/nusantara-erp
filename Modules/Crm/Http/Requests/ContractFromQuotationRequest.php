<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\ScopeType;

/**
 * POST quotations/{quotation}/create-contract (T3.6).
 *
 * What is NOT here is the point. customer_id and quotation_id are the
 * quotation's own and are never read from the request — a contract "from" a
 * quotation but for another customer is exactly the retyping this endpoint
 * replaces (QTN/2026/VIII/0008 Rp 2,04 M → CTR/2026/VIII/0004 Rp 1,84 M for
 * the same deal, production 4 Sep 2026, ANALISIS-PROSES A1). title, scope_type
 * and ppn_rate default to the quotation's and may be overridden (the signed
 * title often differs from the offer's); value defaults to the quotation's
 * DPP, and a different value needs value_change_reason — that rule lives in
 * ContractService, which knows the amount to compare against, so the SPA's
 * contract form and this endpoint refuse the same way. The schedule is the
 * caller's: a quotation carries no termins, so none can be proposed from it.
 */
class ContractFromQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'scope_type' => ['sometimes', 'required', Rule::enum(ScopeType::class)],
            'contract_number_customer' => ['nullable', 'string', 'max:100'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'value_change_reason' => ['nullable', 'string', 'max:500'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sign_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'retention_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'termins' => ['required', 'array', 'min:1'],
            'termins.*.name' => ['required', 'string', 'max:100'],
            'termins.*.percent' => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'termins.*.billing_condition' => ['nullable', 'string', 'max:1000'],
            'termins.*.is_retention' => ['nullable', 'boolean'],
            'termins.*.due_date' => ['nullable', 'date'],
        ];
    }
}
