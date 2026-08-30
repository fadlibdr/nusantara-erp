<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Register dokumen lelang, diganti utuh (pola baris dokumen repo).
 *
 * Aturan urutan addendum sengaja TIDAK di sini: ia berlaku atas SELURUH
 * register sekaligus, bukan atas satu baris, dan sebuah seeder atau perintah
 * konsol yang memanggil service langsung harus tunduk pada aturan yang sama.
 * TenderPackageService yang memaksakannya (pelajaran A7, SettingService).
 */
class TenderDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['documents' => ['present', 'array']] + self::lineRules();
    }

    /**
     * The row rules, reused by the header requests so a package created with
     * its register in one call is validated exactly as one that fills the
     * register afterwards. One list; two callers cannot drift.
     *
     * @return array<string, array<int, string>>
     */
    public static function lineRules(): array
    {
        return [
            'documents.*.title' => ['required', 'string', 'max:250'],
            'documents.*.chapter' => ['nullable', 'string', 'max:120'],
            'documents.*.issued_date' => ['required', 'date'],
            'documents.*.addendum_no' => ['nullable', 'integer', 'min:1', 'max:99'],
            'documents.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'documents.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
