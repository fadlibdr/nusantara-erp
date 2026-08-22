<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\CostCategory;

class ApBillStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // From-PO / from-receipt / from-claim mode: header amounts copied
            // from the source. When several are sent the PO wins, then the goods
            // receipt (see ApBillService::create).
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('prc_purchase_orders', 'id')],
            // Settles a PO-less goods receipt: the bill debits back the accrual
            // that receipt credited instead of expensing the goods again.
            'goods_receipt_id' => ['nullable', 'integer', Rule::exists('inv_goods_receipts', 'id')],
            'subcontract_claim_id' => ['nullable', 'integer', Rule::exists('scm_progress_claims', 'id')],

            // Uang muka (down payment) against a PO. It skips the goods-received
            // gate, debits the purchase advance asset account and is netted off
            // by the final bill, so its DPP must be stated explicitly — there is
            // no rule that derives it from the PO.
            'is_advance' => ['nullable', 'boolean'],

            // Tagihan parsial (#40): bill only these POSTED receipts of the
            // chosen PO — priced at received qty x the PO unit price. The
            // service re-validates ownership/status inside its transaction;
            // these rules just keep garbage ids out of it.
            'goods_receipt_ids' => ['nullable', 'array'],
            'goods_receipt_ids.*' => ['integer', Rule::exists('inv_goods_receipts', 'id')],

            // Manual mode.
            'vendor_id' => ['required_without_all:purchase_order_id,goods_receipt_id,subcontract_claim_id', 'integer', Rule::exists('prc_vendors', 'id')],
            'project_id' => ['nullable', 'integer'],
            // Which RAP bucket this bill charges. Left blank it is derived from
            // the source document, which called every PO purchase material —
            // including a crane hired on a services PO. See
            // ApBillService::costCategory().
            'cost_category' => ['nullable', Rule::enum(CostCategory::class)],
            'description' => ['required_without_all:purchase_order_id,goods_receipt_id,subcontract_claim_id', 'string', 'max:500'],
            'dpp' => [
                'required_without_all:purchase_order_id,goods_receipt_id,subcontract_claim_id',
                Rule::requiredIf(fn (): bool => $this->boolean('is_advance')),
                'numeric',
                'min:0.01',
            ],
            'ppn_amount' => ['nullable', 'numeric', 'min:0'],
            // whereNull('deleted_at') because Rule::exists does not exclude a
            // soft-deleted row: a stale id from a cached lookup would otherwise
            // name a scheme that no longer resolves to a liability account.
            'pph_tax_id' => ['nullable', 'integer', Rule::exists('fin_taxes', 'id')->whereNull('deleted_at')],
            'pph_amount' => ['nullable', 'numeric', 'min:0'],

            // Common
            'bill_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'vendor_invoice_no' => ['required', 'string', 'max:60'],
            'faktur_pajak_no' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Two pairs the service also refuses; reported here as field errors rather
     * than as a 422 message, so the operator is pointed at the box to fix.
     *
     * (1) An advance is netted off against the final bill for the SAME purchase
     * order, so a prepayment with no PO would debit Uang Muka Proyek with
     * nothing in the product able to credit it back.
     *
     * (2) A withheld amount with no "Jenis PPh" behind it used to be credited
     * to 2-1220 Hutang PPh 23 whatever it really was — Rp 25.837.500 of PPh
     * final Pasal 4(2) on a subcon opname landed there, overstating that
     * month's PPh 23 SSP by exactly the amount the PPh final SSP was short.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_advance') && ! $this->filled('purchase_order_id')) {
                $validator->errors()->add(
                    'is_advance',
                    'Uang muka hanya dapat dibuat atas pesanan pembelian (PO).',
                );
            }

            if ((float) $this->input('pph_amount', 0) > 0 && ! $this->filled('pph_tax_id')) {
                $validator->errors()->add(
                    'pph_tax_id',
                    'Pilih jenis PPh yang dipotong agar potongannya masuk ke akun hutang pajak yang benar.',
                );
            }

            // (3) The GRN set only means something against ONE purchase order —
            // reported at the field so the operator is pointed at the PO box,
            // not at a 500 from the service.
            if ($this->filled('goods_receipt_ids') && ! $this->filled('purchase_order_id')) {
                $validator->errors()->add(
                    'goods_receipt_ids',
                    'Tagihan parsial menunjuk penerimaan barang dari satu PO; pilih PO-nya lebih dulu.',
                );
            }
        });
    }
}
