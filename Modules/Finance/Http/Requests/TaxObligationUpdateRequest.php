<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxObligationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:0'],
            'ntpn' => ['nullable', 'string', 'max:30'],
            'disetor_date' => ['nullable', 'date'],
            'dilapor_date' => ['nullable', 'date'],
            // The JV linkage is a picked reference, nothing automatic — but it
            // must point at a journal that exists and is not soft-deleted.
            'journal_id' => ['nullable', 'integer', Rule::exists('fin_journals', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
