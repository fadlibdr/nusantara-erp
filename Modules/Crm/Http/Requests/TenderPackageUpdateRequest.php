<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenderPackageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // lead_id absent on purpose: a tender dossier does not move prospect.
        return [
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'owner_name' => ['nullable', 'string', 'max:200'],
            'tender_number' => ['nullable', 'string', 'max:100'],
            'registered_at' => ['nullable', 'date'],
            'submission_deadline' => ['nullable', 'date'],
            'aanwijzing_date' => ['nullable', 'date'],
            'aanwijzing_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
        ] + TenderDocumentsRequest::lineRules();
    }
}
