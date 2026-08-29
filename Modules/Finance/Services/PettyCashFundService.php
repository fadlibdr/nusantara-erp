<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Enums\NormalBalance;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PettyCashVoucherStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\KasbonLine;
use Modules\Finance\Models\PettyCashFund;

/**
 * Master data of the imprest drawers, and the ONE number everything else in
 * kas kecil hangs off: balance() — the fund account's posted GL balance.
 *
 * The imprest identity per drawer is
 *
 *   GL balance == float − unreimbursed vouchers − outstanding kasbon
 *                       − unreimbursed settled-kasbon spend
 *                       − unreimbursed wage-offset recoveries
 *
 * computed in ONE place — imprestExpectation() — because the cashier screen
 * used to recompute a shorter formula (float − bon − kasbon) itself and every
 * settled kasbon left a permanent false "Identitas imprest tidak menutup"
 * alert: settle Rp 800.000 of a Rp 1.000.000 advance and the drawer honestly
 * holds 4.200.000, but the short formula said 5.000.000. Settlement spend
 * rides fin_kasbon_lines, never vouchers, so it needs its own term — and a
 * kasbon recovered by a mandor WAGE OFFSET (P4) rides neither pile, so the
 * offset needs its own term too: see unreplenishedWageOffsetTotal().
 *
 * The identity is readable straight off the trial balance because every fund
 * posts to its own 1-11xx leaf. That is why the COA guards below exist: a
 * fund on a credit-normal account, a group, a bank account's COA leaf, an
 * account shared with another fund — or any account OUTSIDE the 1-11xx Kas
 * family, which CashFlowActivityMap::cashAccountIds() would not pool — would
 * each make the identity unreadable or put drawer cash outside every cash
 * report.
 */
class PettyCashFundService
{
    public function create(array $data): PettyCashFund
    {
        return DB::transaction(function () use ($data): PettyCashFund {
            $this->assertFloatPositive((float) $data['float_amount']);
            $this->assertFundAccount((int) $data['coa_account_id'], null);

            return PettyCashFund::query()->create(Arr::only($data, [
                'code', 'name', 'coa_account_id', 'custodian_id', 'project_id',
                'float_amount', 'max_voucher_amount', 'max_kasbon_amount', 'is_active',
            ]));
        });
    }

    public function update(PettyCashFund $fund, array $data): PettyCashFund
    {
        return DB::transaction(function () use ($fund, $data): PettyCashFund {
            if (array_key_exists('float_amount', $data)) {
                $this->assertFloatPositive((float) $data['float_amount']);
            }

            $newAccountId = (int) ($data['coa_account_id'] ?? $fund->coa_account_id);

            if ($newAccountId !== (int) $fund->coa_account_id) {
                // Repointing a drawer with money in it would orphan the balance
                // on the old account: the imprest identity would read off an
                // account no fund claims any more.
                $balance = $this->balance($fund);

                if ($this->cents($balance) !== 0) {
                    throw new LogicException(
                        "Akun COA kas kecil {$fund->code} tidak dapat diganti selama saldonya bukan nol "
                        ."(saldo sekarang {$balance}). Kembalikan dananya ke bank lebih dulu."
                    );
                }

                $this->assertFundAccount($newAccountId, (int) $fund->id);
            }

            $fund->fill(Arr::only($data, [
                'code', 'name', 'coa_account_id', 'custodian_id', 'project_id',
                'float_amount', 'max_voucher_amount', 'max_kasbon_amount', 'is_active',
            ]))->save();

            return $fund->refresh();
        });
    }

    public function delete(PettyCashFund $fund): void
    {
        $balance = $this->balance($fund);

        if ($this->cents($balance) !== 0) {
            throw new LogicException(
                "Kas kecil {$fund->code} masih memegang saldo {$balance}; "
                .'kembalikan dananya ke bank lebih dulu sebelum menghapus.'
            );
        }

        if ($fund->vouchers()->exists() || $fund->kasbons()->exists()) {
            throw new LogicException(
                "Kas kecil {$fund->code} sudah punya riwayat voucher/kasbon dan tidak dapat dihapus; "
                .'nonaktifkan saja.'
            );
        }

        $fund->delete();
    }

    /**
     * The drawer's posted GL balance (debit − credit on its account) — the
     * same query shape as PeriodCloseService::controlBalance().
     *
     * Undated ("what is in the drawer NOW") for the imprest amount and every
     * screen. The POSTING guards pass $through = the document's own date,
     * because their journal will carry that date: a bon back-dated 2026-05-20
     * against a drawer funded 2026-06-01 used to read the NOW balance
     * (5.000.000), pass, and leave 1-11xx at −3.000.000 on the May balance
     * sheet — cash the drawer had not yet received. Same DATE() discipline as
     * SettleableLiabilities::ceiling()'s month-end window.
     */
    public function balance(PettyCashFund $fund, ?string $through = null): float
    {
        $sums = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            // whereDate, the DanglingDocuments lesson: journal_date is stored
            // "…-06-30 00:00:00" and a raw string <= drops the bound date.
            ->when($through !== null, fn ($query) => $query->whereDate('fin_journals.journal_date', '<=', $through))
            ->where('fin_journal_lines.account_id', $fund->coa_account_id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }

    /**
     * What a replenishment dated today may move — THE one home of the amount,
     * called by the screen, by the submit guard and by the post guard so the
     * three can never quote different numbers.
     *
     * float − balance − OUTSTANDING KASBON, not the bare float − balance it
     * used to be. An issued kasbon is drawer money that left as an ADVANCE:
     * it is still the drawer's, sitting on 1-1370 in an employee's name, and
     * no bon evidences it. Priced at float − balance, a Rp 5.000.000 drawer
     * holding Rp 200.000 of bons and a Rp 1.000.000 outstanding kasbon asked
     * the bank for Rp 1.200.000 while stampCoveredVouchers() showed the
     * approver Rp 200.000 of paper — Rp 1.000.000 of working capital pushed
     * past the authorised float on nobody's signature. Worse, it never closed
     * again: after that top-up balance read 5.000.000 against an
     * imprestExpectation() of 4.000.000, the cashier screen said "identitas
     * imprest tidak menutup" for ever, and once the kasbon settled the drawer
     * sat ABOVE float so no further replenishment was possible at all.
     *
     * Subtracting the advance is exactly the identity imprestExpectation()
     * already publishes (float − bon − kasbon − belanja kasbon), so what comes
     * back is the unreimbursed paper and nothing else. Negative means the
     * drawer holds MORE than it should (a return is due, not a top-up).
     */
    public function replenishmentDue(PettyCashFund $fund): float
    {
        return round(
            (float) $fund->float_amount
            - $this->balance($fund)
            - $this->outstandingKasbonTotal($fund),
            2,
        );
    }

    /**
     * Posted bons not yet REIMBURSED — the "bon belum diganti" figure.
     *
     * Not "not yet stamped": stampCoveredVouchers() stamps at SUBMIT, before
     * any money moves, and a whereNull() here zeroed the figure for the whole
     * submitted→approved→posted window (and snapped it back on reject) while
     * Rp 200.000 of bons genuinely awaited reimbursement. A bon only stops
     * counting when the payment that covers it has POSTED.
     */
    public function unreplenishedVoucherTotal(PettyCashFund $fund): float
    {
        return round((float) $fund->vouchers()
            ->where('status', PettyCashVoucherStatus::Posted->value)
            ->where(function ($query): void {
                $query->whereNull('replenishment_payment_id')
                    ->orWhereHas(
                        'replenishmentPayment',
                        fn ($payment) => $payment->where('status', '!=', PaymentStatus::Posted->value),
                    );
            })
            ->sum('amount'), 2);
    }

    /**
     * Advances handed out and not yet accounted for — money that left the
     * drawer and sits on 1-1370, per employee.
     */
    public function outstandingKasbonTotal(PettyCashFund $fund): float
    {
        return round((float) $fund->kasbons()
            ->where('status', KasbonStatus::Issued->value)
            ->sum('amount'), 2);
    }

    /**
     * Drawer cash consumed by SETTLED kasbon and not yet reimbursed: the sum
     * of fin_kasbon_lines for the fund's settled kasbons whose replenishment
     * stamp is absent or unposted (same "reimbursed means POSTED" rule as
     * unreplenishedVoucherTotal).
     *
     * Σ lines IS the drawer impact of a whole RECEIPTS-ONLY kasbon lifecycle
     * regardless of how the change fell: advance 1.000.000 with 800.000 of
     * receipts returns 200.000 (net −800.000); an overspent 1.300.000
     * settlement pays the extra 300.000 out of the drawer (net −1.300.000).
     * Either way the drawer is down by exactly Σ lines. A kasbon partly
     * recovered by wage offset is down by Σ lines PLUS the offset — the
     * offset slice is unreplenishedWageOffsetTotal()'s, never this term's,
     * so the two can never count the same rupiah twice.
     */
    public function settledKasbonSpendTotal(PettyCashFund $fund): float
    {
        return round((float) KasbonLine::query()
            ->whereHas('kasbon', function ($query) use ($fund): void {
                $query->where('fund_id', $fund->id)
                    ->where('status', KasbonStatus::Settled->value)
                    ->where(function ($stamp): void {
                        $stamp->whereNull('replenishment_payment_id')
                            ->orWhereHas(
                                'replenishmentPayment',
                                fn ($payment) => $payment->where('status', '!=', PaymentStatus::Posted->value),
                            );
                    });
            })
            ->sum('amount'), 2);
    }

    /**
     * Kasbon money recovered by a mandor WAGE OFFSET (P4) and not yet
     * reimbursed — the identity's fourth subtraction.
     *
     * Follow the drawer's claim through the offset. At issue the drawer
     * swapped cash for paper: Dr 1-1370 / Cr fund — cash left, an equal
     * receivable arrived, the identity unmoved. When the wage bill posts,
     * its own journal credits 1-1370 for the deduction
     * (ApBillService::approve → KasbonService::offsetAgainstWageBill): the
     * drawer's receivable is CONSUMED by the wage payable — the employee's
     * debt paid the mandor's wages — and no cash returns to the drawer.
     * From that posting the drawer's total claim (cash + paper) is short by
     * exactly the offset, and only the next POSTED replenishment restores
     * it. The expectation must fall the moment the ledger does; before this
     * term existed, a full 2.000.000 offset on a 5.000.000 float read as a
     * permanent 2.000.000 "finding" on the cashier screen.
     *
     * SETTLED kasbons only, deliberately: while a partly-offset kasbon is
     * still ISSUED, outstandingKasbonTotal() subtracts its FULL face amount,
     * which already contains the recovered slice — counting
     * wage_offset_total there too would subtract the same rupiah twice. The
     * moment the kasbon flips Settled (full offset, or receipts for the
     * remainder), the face amount leaves the outstanding term and Σ lines
     * covers only the receipts — the offset slice would vanish from the
     * identity; this term catches it.
     *
     * Same "reimbursed means POSTED" stamp rule as the other two piles:
     * stampCoveredVouchers() stamps every settled kasbon — offset-settled,
     * zero-line ones included — so this term clears when the replenishment
     * posts and holds through submit/approve/reject exactly like the rest.
     * releaseWageOffset() (wage-bill cancel) needs no seam here: its
     * reversal re-debits 1-1370 and flips the kasbon back to Issued, so the
     * offset leaves this term as the face amount re-enters outstanding —
     * the expectation is restored exactly.
     */
    public function unreplenishedWageOffsetTotal(PettyCashFund $fund): float
    {
        return round((float) $fund->kasbons()
            ->where('status', KasbonStatus::Settled->value)
            ->where(function ($stamp): void {
                $stamp->whereNull('replenishment_payment_id')
                    ->orWhereHas(
                        'replenishmentPayment',
                        fn ($payment) => $payment->where('status', '!=', PaymentStatus::Posted->value),
                    );
            })
            ->sum('wage_offset_total'), 2);
    }

    /**
     * THE imprest identity, in its one home (see the class docblock):
     * what the drawer's GL balance should read given the paper —
     * float − unreimbursed bons − outstanding kasbon − unreimbursed
     * settled-kasbon spend − unreimbursed wage-offset recoveries. The
     * cashier screen compares this against balance() and a difference is a
     * finding (short initial funding, a partial top-up), not noise.
     */
    public function imprestExpectation(PettyCashFund $fund): float
    {
        return round(
            (float) $fund->float_amount
            - $this->unreplenishedVoucherTotal($fund)
            - $this->outstandingKasbonTotal($fund)
            - $this->settledKasbonSpendTotal($fund)
            - $this->unreplenishedWageOffsetTotal($fund),
            2,
        );
    }

    // ------------------------------------------------------------- guards

    private function assertFloatPositive(float $float): void
    {
        if ($float <= 0) {
            throw new LogicException('Nilai dana tetap (float) kas kecil harus lebih besar dari nol.');
        }
    }

    /**
     * The account a drawer may post to: postable, active, asset, debit-normal,
     * not a bank account's COA leaf, not another fund's. Ordered so the most
     * actionable refusal comes first, same as SettleableLiabilities.
     */
    private function assertFundAccount(int $accountId, ?int $exceptFundId): void
    {
        $account = Account::query()->find($accountId);

        if ($account === null) {
            throw new LogicException('Akun COA untuk kas kecil tidak ditemukan di bagan akun.');
        }

        if (! $account->is_active) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} sudah nonaktif dan tidak dapat menjadi akun kas kecil."
            );
        }

        if (! $account->is_postable) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} adalah akun kelompok; buat akun anak 1-11xx "
                .'(mis. 1-1110 Kas Kecil Kantor Pusat) di bawah 1-1100 Kas.'
            );
        }

        if ($account->account_type !== AccountType::Asset
            || $account->normal_balance !== NormalBalance::Debit) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} bukan akun aset bersaldo normal debit; "
                .'uang tunai di laci adalah aset.'
            );
        }

        if (BankAccount::query()->where('coa_account_id', $accountId)->exists()) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} sudah dipakai rekening bank; "
                .'kas kecil butuh akun 1-11xx miliknya sendiri agar saldonya bisa diaudit terpisah.'
            );
        }

        $taken = PettyCashFund::query()
            ->where('coa_account_id', $accountId)
            ->when($exceptFundId !== null, fn ($query) => $query->where('id', '!=', $exceptFundId))
            ->first();

        if ($taken !== null) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} sudah dipakai kas kecil {$taken->code}; "
                .'satu laci satu akun — laci yang rusak tidak boleh bersembunyi di dalam jumlah laci lain.'
            );
        }

        /*
         * The family the messages above already promised but nothing enforced:
         * a drawer on 1-1500 Uang Muka Proyek passed every check here, then
         * CashFlowActivityMap::cashAccountIds() (pooling 1-11%/1-12% only)
         * read its Rp 5.000.000 bank→drawer top-up as an OPERATING OUTFLOW,
         * closing cash excluded the drawer, and bankBalances() omitted it. A
         * drawer on 1-1370 is worse still — kasbon issue would book
         * Dr 1-1370 / Cr 1-1370 and the balance never moves. LAST because a
         * bank-claimed 1-12xx leaf should hear "sudah dipakai rekening bank",
         * the more actionable refusal, before hearing about the family.
         */
        if (! str_starts_with((string) $account->code, '1-11')) {
            throw new LogicException(
                "Akun {$account->code} {$account->name} bukan akun kas 1-11xx; kas kecil harus berada "
                .'di keluarga Kas agar laci ikut kumpulan kas laporan arus kas dan saldo bank.'
            );
        }
    }

    /** Whole-cent compare, same reason as JournalService::assertBalanced(). */
    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
