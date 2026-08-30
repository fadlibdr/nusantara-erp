<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\TkdnCostGroup;
use Modules\Crm\Enums\TkdnNationality;
use Modules\Crm\Enums\TkdnOrigin;
use Modules\Crm\Enums\TkdnOwnership;

/**
 * Baris biaya lembar TKDN, diganti utuh.
 *
 * Bentuk saja yang diperiksa di sini. Aturan yang benar-benar menentukan —
 * kolom penentu mana yang WAJIB bagi kelompok biaya mana (Permenperin 35/2025
 * Lampiran IV huruf B) — hidup di TkdnService, karena ia adalah aturan
 * silang antar-kolom dan karena angka yang dihasilkannya dikutip pada dokumen
 * penawaran: sebuah seeder yang memanggil service langsung harus tunduk pada
 * aturan yang sama.
 */
class TkdnWorksheetItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['items' => ['present', 'array']] + self::lineRules();
    }

    /**
     * The row rules, reused by the header request so a worksheet created with
     * its rows in one call is validated exactly as one that fills them
     * afterwards.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function lineRules(): array
    {
        return [
            'items.*.quotation_item_id' => ['required', 'integer', Rule::exists('crm_quotation_items', 'id')],
            'items.*.cost_group' => ['required', Rule::enum(TkdnCostGroup::class)],
            'items.*.description' => ['required', 'string', 'max:250'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'items.*.nationality' => ['nullable', Rule::enum(TkdnNationality::class)],
            'items.*.made_in' => ['nullable', Rule::enum(TkdnOrigin::class)],
            'items.*.owned_by' => ['nullable', Rule::enum(TkdnOwnership::class)],
            'items.*.domestic_share_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.provider_origin' => ['nullable', Rule::enum(TkdnOrigin::class)],
        ];
    }
}
