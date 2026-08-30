<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenderPackageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // whereNull(deleted_at) for the GuaranteeStoreRequest reason: a
            // trashed lead resolves to null through the global scope, and the
            // dossier would be unreachable from every screen.
            'lead_id' => ['required', 'integer', Rule::exists('crm_leads', 'id')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:250'],
            'owner_name' => ['nullable', 'string', 'max:200'],
            'tender_number' => ['nullable', 'string', 'max:100'],
            'registered_at' => ['nullable', 'date'],
            'submission_deadline' => ['nullable', 'date'],
            'aanwijzing_date' => ['nullable', 'date'],
            'aanwijzing_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            // Register dokumen boleh datang bersama kepalanya (layar generik
            // mengirim header + lines dalam satu simpanan); aturan urutan
            // addendum tetap dijalankan service.
            'documents' => ['nullable', 'array'],
        ] + TenderDocumentsRequest::lineRules();
    }
}
