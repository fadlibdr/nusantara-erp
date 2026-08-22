<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdvancePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route (scm.post AND fin.approve)
    }

    public function rules(): array
    {
        return [
            'payout_date' => ['required', 'date'],
        ];
    }
}
