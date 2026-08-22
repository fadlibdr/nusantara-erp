<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('fin_bank_accounts', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_no' => ['required', 'string', 'max:40'],
            'account_name' => ['required', 'string', 'max:150'],
            'coa_account_id' => ['required', 'integer', Rule::exists('fin_accounts', 'id'), Rule::unique('fin_bank_accounts', 'coa_account_id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }
}
