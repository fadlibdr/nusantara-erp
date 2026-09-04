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
            // P4 — opname mandor (SP3) yang ditagihkan tagihan ini.
            'labor_claim_id' => $this->labor_claim_id,
            // P5 — tagihan periode PPK yang ditagihkan tagihan ini.
            'work_order_billing_id' => $this->work_order_billing_id,
            'work_order_billing' => $this->whenLoaded('workOrderBilling', fn () => $this->workOrderBilling === null ? null : [
                'id' => $this->workOrderBilling->id,
                'code' => $this->workOrderBilling->code,
                'period_start' => $this->workOrderBilling->period_start?->toDateString(),
                'period_end' => $this->workOrderBilling->period_end?->toDateString(),
            ]),
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
            // Jejak persetujuan, bentuk PaymentResource — satu perender di SPA
            // (approvalTimeline) untuk semua dokumen; hanya bila show() memuatnya (T3.3).
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval): array => [
                'id' => $approval->id,
                'action' => $approval->action,
                'note' => $approval->note,
                'created_at' => $approval->created_at?->toIso8601String(),
                'user' => $approval->relationLoaded('user') && $approval->user !== null
                    ? ['id' => $approval->user->id, 'name' => $approval->user->name]
                    : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
