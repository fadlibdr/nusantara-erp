<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class PaymentAllocation extends BaseModel
{
    public const TYPE_AR_INVOICE = 'ar_invoice';

    public const TYPE_AP_BILL = 'ap_bill';

    /**
     * A non-AP liability settled straight on its GL account (payable_id is
     * fin_accounts.id): net payroll on 2-1110, SSP tax remittances, BPJS.
     * Only accounts in Support\SettleableLiabilities may appear here.
     */
    public const TYPE_GL_ACCOUNT = 'gl_account';

    /**
     * A petty-cash drawer top-up (PAY: Dr fund / Cr bank) or return (RCV:
     * Dr bank / Cr fund); payable_id is fin_petty_cash_funds.id. Valid only on
     * a payment whose petty_cash_fund_id names the same fund, always exactly
     * one row, amount pinned to the imprest rule (float − GL balance).
     */
    public const TYPE_PETTY_CASH_FUND = 'petty_cash_fund';

    protected $table = 'fin_payment_allocations';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * Resolve the settled document — or, for a gl_account row, the liability
     * account itself, and for a petty_cash_fund row, the drawer. Manual
     * "morph" over the payable types — the column stores short keys, not
     * FQCNs. All four targets carry ->code, which is what
     * PaymentAllocationResource reads off this.
     */
    public function payable(): ArInvoice|ApBill|Account|PettyCashFund|null
    {
        /*
         * withTrashed on all four, for the reason
         * FinanceFormService::allocationTargets gives about the printed sheet:
         * WHAT a payment settled is a fact of the payment, not of the settled
         * document's current lifecycle. All four soft-delete.
         *
         * It has to hold HERE too because this is the SCREEN. With the paper
         * constrained and this left plain, the voucher in the operator's hand
         * named BIL/2026/VIII/0012 while the payment screen beside it showed a
         * bare row number — two answers to one question, and the paper is the
         * one that gets signed.
         */
        return match ($this->payable_type) {
            self::TYPE_AR_INVOICE => ArInvoice::query()->withTrashed()->find($this->payable_id),
            self::TYPE_AP_BILL => ApBill::query()->withTrashed()->find($this->payable_id),
            self::TYPE_GL_ACCOUNT => Account::query()->withTrashed()->find($this->payable_id),
            self::TYPE_PETTY_CASH_FUND => PettyCashFund::query()->withTrashed()->find($this->payable_id),
            default => null,
        };
    }
}
