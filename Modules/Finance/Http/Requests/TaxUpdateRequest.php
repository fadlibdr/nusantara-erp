<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\TaxType;

class TaxUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('fin_taxes', 'code')->ignore($this->route('tax')?->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_type' => ['sometimes', Rule::enum(TaxType::class)],
            'object_code' => ['nullable', 'string', 'max:20'],
            'coa_account_id' => ['nullable', 'integer', Rule::exists('fin_accounts', 'id')],
            'notes' => ['nullable', 'string'],
        ];
    }
}
