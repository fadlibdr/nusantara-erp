<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TkdnWorksheetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quotation_id' => ['required', 'integer', Rule::exists('crm_quotations', 'id')->whereNull('deleted_at')],
            'tender_package_id' => ['nullable', 'integer', Rule::exists('crm_tender_packages', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string'],
            // Baris komponen boleh datang bersama kepalanya; kolom penentu mana
            // yang wajib bagi kelompok biaya mana tetap dijaga TkdnService.
            'items' => ['nullable', 'array'],
        ] + TkdnWorksheetItemsRequest::lineRules();
    }
}
