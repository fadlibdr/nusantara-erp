<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MppXmlImportRequest extends FormRequest
{
    /**
     * 5 MB of XML — the same file ceiling the spreadsheet importers hold
     * (SpreadsheetReader::MAX_BYTES); a schedule bigger than that is not a
     * schedule anybody drew by hand.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:160'],
            // base64 dari isi berkas XML — pola yang sama dengan
            // document-import: SPA membaca berkas lokal dan mengirim JSON.
            'content' => ['required', 'string', 'max:'.(int) ceil(self::MAX_BYTES * 4 / 3 + 16)],
            'buat_baseline' => ['sometimes', 'boolean'],
            'bac_override' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
