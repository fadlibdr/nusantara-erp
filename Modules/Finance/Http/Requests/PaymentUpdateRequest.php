<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Direction is fixed once the RCV/PAY number is issued.
            'payment_date' => ['sometimes', 'date'],
            'bank_account_id' => ['sometimes', 'integer', Rule::exists('fin_bank_accounts', 'id')],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
