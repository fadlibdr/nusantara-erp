<?php

namespace Modules\Finance\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\PaymentAllocation;

/**
 * The liability accounts a payment may settle DIRECTLY (payable_type
 * 'gl_account'), listed once with the reason each one is here — and, just as
 * loudly, the ones that are NOT and why.
 *
 * A code-level registry, deliberately NOT an is_settleable flag on
 * fin_accounts and NOT "all postable liabilities". A flag would hand the
 * 2-1100 exclusion — a fraud control — to whoever holds fin.update on the
 * chart of accounts, and "all postable liabilities" would include every
 * account below that some engine owns and settles its own way. Account codes
 * are the codebase's stable canon (WithholdingType::accountCode(),
 * PayrollPostingService's constants, settleInvoice's '1-1300').
 *
 * EXCLUDED, with the reason each stays out:
 *
 *  - 2-1100 Hutang Usaha — THE control exclusion. A gl_account debit to
 *    2-1100 would move the GL without settling any bill, which is precisely
 *    the manual-JV fraud shape PeriodCloseService::itemSubledgerTied() exists
 *    to catch (the package-7 Rp 111.000.000 probe). Vendors are paid through
 *    ap_bill allocations, where outstanding() guards every rupiah. Keeping
 *    2-1100 out — and 1-1300, which is an asset and so can never enter a
 *    liability allowlist — is what lets the AP/AR tie-out stay untouched by
 *    this feature.
 *  - 2-1150 Penerimaan Barang Belum Ditagih — GR/IR is cleared only by AP
 *    bill approval matching the receipt; paying it directly would strand the
 *    receipt forever un-billed.
 *  - 2-1400 / 2-1410 — the contract-liability engine: customer advances and
 *    the PSAK 115 runs own these balances and release them themselves.
 *  - 2-1500 Hutang Retensi Subkon — RetentionService mints its own release
 *    bill when the warranty period ends; that bill is the payable, not this
 *    account.
 *  - 2-1700 Provisi Kerugian Kontrak — a provision is never "paid"; the POC
 *    engine builds and releases it.
 *  - 2-2100 Hutang Bank — an installment carries an interest expense leg
 *    (7-2200) an allocation cannot book. Accrue the interest to 2-1600 first,
 *    or wait for a loan-schedule feature (named seam).
 */
class SettleableLiabilities
{
    /**
     * code => the one-line WHY, shown to nobody but the next maintainer.
     *
     * @var array<string, string>
     */
    public const SETTLEABLE = [
        '2-1110' => 'Hutang Gaji & Upah — net payroll; the Rp 166.638.981,43 June run is the headline case',
        '2-1120' => 'Hutang BPJS — employee + employer contributions, remitted monthly to BPJS',
        '2-1210' => 'Hutang PPh 21 — withheld from wages by payroll, remitted via SSP',
        '2-1220' => 'Hutang PPh 23 — credited by AP bills that withhold vendor PPh (ApBillService)',
        '2-1230' => 'Hutang PPh Final 4(2) — subcontractor konstruksi withholding, remitted via SSP',
        '2-1240' => 'Hutang PPh Badan — corporate income tax installments (PPh 25/29)',
        '2-1300' => 'PPN Keluaran — the net kurang bayar after the input-VAT offset JV (Dr 2-1300 / Cr 1-1600)',
        '2-1600' => 'Beban YMH Dibayar — paying down hand-keyed accruals',
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::SETTLEABLE);
    }

    public static function contains(string $code): bool
    {
        return array_key_exists($code, self::SETTLEABLE);
    }

    /**
     * Refuse anything a payment must not settle directly, in the operator's
     * language. The allowlist test comes LAST so an inactive or group account
     * that also is not listed gets the more actionable message first.
     */
    public static function assertSettleable(Account $account): void
    {
        if (! $account->is_active) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} sudah nonaktif dan tidak dapat menerima pembayaran."
            );
        }

        if (! $account->is_postable) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} adalah akun kelompok dan tidak dapat menerima pembayaran."
            );
        }

        if (! self::contains((string) $account->code)) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} tidak termasuk kewajiban yang dapat dilunasi "
                .'langsung lewat pembayaran. Hutang vendor dilunasi lewat tagihannya; akun lain punya '
                .'mekanisme pelunasannya sendiri.'
            );
        }
    }

    /**
     * How much of this liability a payment dated $paymentDate may settle:
     * POSTED credits through the END OF THE PAYMENT'S MONTH, minus POSTED
     * debits with NO date bound at all.
     *
     * The month-end window on the CREDIT leg, not payment_date, is forced by
     * the live data twice over: PYR/2026/06/002 pays 2026-06-25 against an
     * accrual journal dated 2026-06-30, and PYR/2026/03/001 pays 03-16 against
     * 03-31. Payroll always pays before its accrual's journal date, so an
     * as-at-payment-date ceiling would refuse the exact flow this feature
     * exists for.
     *
     * The DEBIT leg is deliberately unbounded: every debit on a settleable
     * liability is a settlement or a reclass, and it must shrink the ceiling
     * no matter which month it was booked in. Bounding it by the same window
     * made a settlement posted in a LATER month invisible — Cr 2-1110
     * Rp 166.638.981,43 dated 2026-06-30, PAY posted 2026-07-05 for the full
     * amount, then a second PAY back-dated 2026-06-25 (the real pay day is how
     * duplicates get keyed) saw a ceiling of 166.638.981,43 again and the June
     * wages left BCA twice.
     */
    public static function ceiling(Account $account, string $paymentDate): float
    {
        $end = CarbonImmutable::parse($paymentDate)->endOfMonth()->toDateString();

        $sums = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->where('fin_journal_lines.account_id', $account->id)
            // DATE(), same reason as PeriodCloseService::controlBalance()'s
            // whereDate: journal_date is cast `date` and stored
            // "…-06-30 00:00:00", which a raw string <= drops on the last day
            // of the month.
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN DATE(fin_journals.journal_date) <= ? THEN credit ELSE 0 END), 0) as c, '
                .'COALESCE(SUM(debit), 0) as d',
                [$end],
            )
            ->first();

        return round((float) $sums->c - (float) $sums->d, 2);
    }

    /**
     * gl_account allocations already riding OTHER unposted (submitted or
     * approved) payments against this account. Subtracted from the ceiling at
     * submit time so a doomed second payment is refused before an approver
     * ever signs it — at post time only the posted GL matters, because the
     * first payment's debit has shrunk it by then.
     */
    public static function pendingAllocations(int $accountId, ?int $exceptPaymentId = null): float
    {
        return round((float) DB::table('fin_payment_allocations')
            ->join('fin_payments', 'fin_payments.id', '=', 'fin_payment_allocations.payment_id')
            ->where('fin_payment_allocations.payable_type', PaymentAllocation::TYPE_GL_ACCOUNT)
            ->where('fin_payment_allocations.payable_id', $accountId)
            ->whereIn('fin_payments.status', [PaymentStatus::Submitted->value, PaymentStatus::Approved->value])
            ->whereNull('fin_payments.deleted_at')
            ->when($exceptPaymentId !== null, fn ($query) => $query->where('fin_payments.id', '!=', $exceptPaymentId))
            ->sum('fin_payment_allocations.amount'), 2);
    }
}
