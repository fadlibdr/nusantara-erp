<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JournalUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string', 'max:500'],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'reference_id' => ['nullable', 'integer'],
            // Lines are replaced wholesale when present.
            'lines' => ['sometimes', 'array', 'min:2'],
            'lines.*.account_id' => ['required_with:lines', 'integer', Rule::exists('fin_accounts', 'id')],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.project_id' => ['nullable', 'integer'],
        ];
    }
}
