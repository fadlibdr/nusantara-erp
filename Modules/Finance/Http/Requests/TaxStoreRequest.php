<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\TaxType;

class TaxStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('fin_taxes', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_type' => ['required', Rule::enum(TaxType::class)],
            'object_code' => ['nullable', 'string', 'max:20'],
            'coa_account_id' => ['nullable', 'integer', Rule::exists('fin_accounts', 'id')],
            'notes' => ['nullable', 'string'],
        ];
    }
}
