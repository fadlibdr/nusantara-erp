<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\PettyCashVoucher;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'direction' => $this->direction?->value,
            'direction_label' => $this->direction?->label(),
            'payment_date' => $this->payment_date?->toDateString(),
            'bank_account_id' => $this->bank_account_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => [
                'id' => $this->bankAccount->id,
                'code' => $this->bankAccount->code,
                'name' => $this->bankAccount->name,
                'bank_name' => $this->bankAccount->bank_name,
            ]),
            'amount' => $this->amount,
            'reference' => $this->reference,
            'notes' => $this->notes,
            // Drawer top-up / return marker; null on ordinary payments.
            'petty_cash_fund_id' => $this->petty_cash_fund_id,
            'petty_cash_fund' => $this->whenLoaded('pettyCashFund', fn () => [
                'id' => $this->pettyCashFund->id,
                'code' => $this->pettyCashFund->code,
                'name' => $this->pettyCashFund->name,
                'float_amount' => $this->pettyCashFund->float_amount,
            ]),
            // The frozen review set stamped at submit: the bons this transfer
            // reimburses, so the approver reads what the money pays back. One
            // query, single-payment views only — same budget as payable().
            'covered_vouchers' => $this->when($this->petty_cash_fund_id !== null, fn () => PettyCashVoucher::query()
                ->where('replenishment_payment_id', $this->id)
                ->orderBy('voucher_date')
                ->orderBy('id')
                ->get()
                ->map(fn (PettyCashVoucher $voucher): array => [
                    'id' => $voucher->id,
                    'code' => $voucher->code,
                    'voucher_date' => $voucher->voucher_date?->toDateString(),
                    'category' => $voucher->category?->value,
                    'category_label' => $voucher->category?->label(),
                    'description' => $voucher->description,
                    'amount' => $voucher->amount,
                ])->values()),
            // The kasbon half of the same frozen set: settled kasbons whose
            // receipts (fin_kasbon_lines) this transfer also pays back.
            // Without them the approver of a Rp 1.000.000 top-up covering
            // Rp 200.000 of bons plus Rp 800.000 of settled-kasbon receipts
            // saw Rp 800.000 with no supporting document. `spend` (Σ lines)
            // is the drawer money this kasbon consumed; the advance and the
            // change are context.
            'covered_kasbons' => $this->when($this->petty_cash_fund_id !== null, fn () => Kasbon::query()
                ->with('lines')
                ->where('replenishment_payment_id', $this->id)
                ->orderBy('settlement_date')
                ->orderBy('id')
                ->get()
                ->map(fn (Kasbon $kasbon): array => [
                    'id' => $kasbon->id,
                    'code' => $kasbon->code,
                    'settlement_date' => $kasbon->settlement_date?->toDateString(),
                    'purpose' => $kasbon->purpose,
                    'amount' => $kasbon->amount,
                    'spend' => round((float) $kasbon->lines->sum('amount'), 2),
                    'cash_returned' => $kasbon->cash_returned,
                ])->values()),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Who reversed a posted payment, when and why. Carried on the row
            // as well as in the approval trail because every screen that shows
            // a reversed payment already has the payment in hand, and "Dibalik"
            // with no reason is the one thing an auditor cannot work with.
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'withholdings' => PaymentWithholdingResource::collection($this->whenLoaded('withholdings')),
            // The same approval timeline every other document draws. Without it
            // an approver opening a submitted disbursement sees an amount and a
            // bank account and no way of telling who asked for it.
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
