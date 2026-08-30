<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Baris biaya SMKK, diganti utuh.
 *
 * TIDAK ADA FIELD RUPIAH DI SINI, dan tidak akan pernah ada: nilai biaya SMKK
 * adalah nilai baris RAB yang ditunjuk (lihat migrasi 000392). Sebuah field
 * `amount` pada endpoint ini akan menjadi angka kedua untuk uang yang sama.
 */
class RkkSmkkCostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'smkk_costs' => ['present', 'array'],
            'smkk_costs.*.boq_item_id' => ['required', 'integer', 'min:1'],
            'smkk_costs.*.category' => ['nullable', 'string', 'max:80'],
            'smkk_costs.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'smkk_costs.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
