<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('fin_bank_accounts', 'code')->ignore($this->route('bankAccount')?->id)],
            'name' => ['sometimes', 'string', 'max:100'],
            'bank_name' => ['sometimes', 'string', 'max:100'],
            'account_no' => ['sometimes', 'string', 'max:40'],
            'account_name' => ['sometimes', 'string', 'max:150'],
            'coa_account_id' => ['sometimes', 'integer', Rule::exists('fin_accounts', 'id'), Rule::unique('fin_bank_accounts', 'coa_account_id')->whereNull('deleted_at')->ignore($this->route('bankAccount'))],
            'is_active' => ['boolean'],
        ];
    }
}
