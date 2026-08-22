<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'vendor_id' => $this->vendor_id,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'code' => $this->vendor->code,
                'name' => $this->vendor->name,
            ]),
            'project_id' => $this->project_id,
            'cost_category' => $this->cost_category?->value,
            'cost_category_label' => $this->cost_category?->label(),
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder === null ? null : [
                'id' => $this->purchaseOrder->id,
                'code' => $this->purchaseOrder->code,
            ]),
            // Tagihan parsial: the receipts this bill covers, with the priced
            // slice and (after approval) the clearing actually swept per GRN.
            'billed_receipts' => $this->whenLoaded('billedReceipts', fn () => $this->billedReceipts
                ->map(fn ($claim): array => [
                    'goods_receipt_id' => (int) $claim->goods_receipt_id,
                    'goods_receipt_code' => $claim->goodsReceipt?->code,
                    'dpp_amount' => $claim->dpp_amount,
                    'cleared_amount' => $claim->cleared_amount,
                ])->all()),
            'subcontract_claim_id' => $this->subcontract_claim_id,
            'subcontract_claim' => $this->whenLoaded('subcontractClaim', fn () => $this->subcontractClaim === null ? null : [
                'id' => $this->subcontractClaim->id,
                'code' => $this->subcontractClaim->code,
            ]),
            'bill_date' => $this->bill_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'description' => $this->description,
            'dpp' => $this->dpp,
            'ppn_amount' => $this->ppn_amount,
            'pph_tax_id' => $this->pph_tax_id,
            'pph_tax' => $this->whenLoaded('pphTax', fn () => $this->pphTax === null ? null : [
                'id' => $this->pphTax->id,
                'code' => $this->pphTax->code,
                'rate' => $this->pphTax->rate,
            ]),
            'pph_amount' => $this->pph_amount,
            'total_payable' => $this->total_payable,
            'amount_paid' => $this->amount_paid,
            'outstanding' => $this->resource->outstanding(),
            'vendor_invoice_no' => $this->vendor_invoice_no,
            'faktur_pajak_no' => $this->faktur_pajak_no,
            // Nomor bukti potong, minted at approval and never re-derived.
            'bupot_no' => $this->bupot_no,
            'paid_at' => $this->paid_at?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
