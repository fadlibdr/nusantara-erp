<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\CostCategory;

class ApBillUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bill_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string', 'max:500'],
            // Blank falls back to the derivation from the source document; see
            // ApBillService::costCategory().
            'cost_category' => ['nullable', Rule::enum(CostCategory::class)],
            'dpp' => ['sometimes', 'numeric', 'min:0.01'],
            'ppn_amount' => ['sometimes', 'numeric', 'min:0'],
            // whereNull('deleted_at'): Rule::exists does not exclude a
            // soft-deleted row, and a deleted scheme resolves to no liability
            // account. The service refuses it too.
            'pph_tax_id' => ['nullable', 'integer', Rule::exists('fin_taxes', 'id')->whereNull('deleted_at')],
            'pph_amount' => ['sometimes', 'numeric', 'min:0'],
            'vendor_invoice_no' => ['sometimes', 'string', 'max:60'],
            'faktur_pajak_no' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Same coupling ApBillStoreRequest enforces: an amount withheld with no
     * "Jenis PPh" behind it has no liability account of its own and used to be
     * credited to 2-1220 Hutang PPh 23 whatever article it really was.
     *
     * Only checked when the edit SENDS the amount — a partial update that never
     * mentions PPh must not be judged on a value it is not changing; the
     * service asserts the stored pair on every save regardless.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('pph_amount')
                && (float) $this->input('pph_amount', 0) > 0
                && ! $this->filled('pph_tax_id')) {
                $validator->errors()->add(
                    'pph_tax_id',
                    'Pilih jenis PPh yang dipotong agar potongannya masuk ke akun hutang pajak yang benar.',
                );
            }
        });
    }
}
