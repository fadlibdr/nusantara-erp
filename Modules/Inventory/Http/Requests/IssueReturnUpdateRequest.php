<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueReturnUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // issue_id is deliberately NOT a rule — same contract as
            // IssueUpdateRequest's project_id: validated() only returns
            // validated keys, so a payload issue_id never reaches
            // IssueReturnService::update. A return re-pointed at another bon
            // would reverse cost that bon never booked; a draft against the
            // wrong bon is deleted and raised again.
            'return_date' => ['sometimes', 'required', 'date'],
            'reason' => ['sometimes', 'required', 'string', 'min:5', 'max:500'],
            'items' => ['sometimes', 'array', 'min:1'],
            // distinct for the same reason as the store request: the posting
            // ceiling is per bon line, and a duplicated line walks past it.
            'items.*.issue_item_id' => ['required_with:items', 'integer', 'distinct', Rule::exists('inv_issue_items', 'id')],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan retur wajib diisi.',
            'reason.min' => 'Alasan retur terlalu singkat; jelaskan mengapa material ini kembali.',
            'reason.max' => 'Alasan retur maksimal 500 karakter.',
            'items.*.issue_item_id.distinct' => 'Baris bon yang sama tidak boleh muncul dua kali dalam satu retur; gabungkan kuantitasnya.',
        ];
    }
}
