<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueReturnStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'issue_id' => ['required', 'integer', Rule::exists('inv_issues', 'id')->whereNull('deleted_at')],
            'return_date' => ['required', 'date'],
            // Same floor as a cancellation reason: material coming back off a
            // site is the symptom of over-issuing, and "sisa" tells an auditor
            // nothing a year later.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            // Line ownership (the referenced line belongs to THIS bon) is the
            // service's check — it needs the resolved rows, not bare ids.
            // distinct because the posting ceiling is asked PER bon line: two
            // lines naming the same one each fit alone under it, together
            // they return more than the bon issued.
            'items.*.issue_item_id' => ['required', 'integer', 'distinct', Rule::exists('inv_issue_items', 'id')],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
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
