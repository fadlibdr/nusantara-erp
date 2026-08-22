<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\NormalBalance;

class AccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('fin_accounts', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => ['nullable', 'integer', Rule::exists('fin_accounts', 'id')],
            'is_postable' => ['boolean'],
            'normal_balance' => ['required', Rule::enum(NormalBalance::class)],
            'is_active' => ['boolean'],
        ];
    }
}
