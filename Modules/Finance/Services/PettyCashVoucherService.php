<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PettyCashVoucherStatus;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Models\PettyCashVoucher;

/**
 * Voucher kas kecil (PCV): the bon for cash that has ALREADY left the drawer.
 *
 * SATU PENJAGA, BUKAN DUA. A voucher posts on the custodian's say-so alone —
 * no second approver — because by the time it is keyed the warung has been
 * paid and the ojek has left; an approval stage here approves the past. What
 * stands in for the checker at entry time is the fence around the drawer:
 * the poster must BE the custodian, the amount must fit under the per-bon
 * ceiling, and the drawer cannot disburse cash it does not hold. The real
 * second pair of eyes reads the whole voucher pile — receipts attached — at
 * REPLENISHMENT, where an ordinary PAY walks maker-checker before bank money
 * moves (see PaymentService).
 *
 * The custodian guard is strict: not even an admin bypasses it, because the
 * escape hatch that leaves a trail is reassigning custodianship on the fund
 * (fin.update), not posting as somebody else.
 */
class PettyCashVoucherService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly PettyCashFundService $funds,
        private readonly ProjectCostService $projectCosts,
    ) {}

    public function create(array $data, User $by): PettyCashVoucher
    {
        return DB::transaction(function () use ($data, $by): PettyCashVoucher {
            $fund = PettyCashFund::query()->findOrFail((int) $data['fund_id']);

            $this->assertAmountPositive((float) $data['amount']);

            $voucher = new PettyCashVoucher(Arr::only($data, [
                'fund_id', 'voucher_date', 'category', 'description', 'amount',
                'project_id', 'wbs_task_id',
            ]));
            $voucher->status = PettyCashVoucherStatus::Draft;
            $voucher->created_by = $by->id;
            $voucher->save(); // HasDocumentNumber fills the PCV code

            return $voucher->refresh();
        });
    }

    public function update(PettyCashVoucher $voucher, array $data): PettyCashVoucher
    {
        $this->assertDraft($voucher);

        if (array_key_exists('amount', $data)) {
            $this->assertAmountPositive((float) $data['amount']);
        }

        $voucher->fill(Arr::only($data, [
            'voucher_date', 'category', 'description', 'amount', 'project_id', 'wbs_task_id',
        ]))->save();

        return $voucher->refresh();
    }

    public function delete(PettyCashVoucher $voucher): void
    {
        $this->assertDraft($voucher);

        $voucher->delete();
    }

    /**
     * Post the bon: Dr beban (5-xxxx berproyek / 6-xxxx kantor), Cr akun laci.
     *
     * A PROJECT bon also writes its fin_project_costs row here, in the same
     * transaction — this is the line that moves the PSAK 115 cost-to-cost
     * percentage the day the bon is posted instead of waiting for a month-end
     * JV. Reference ('petty_cash_voucher', id), so cancel() can find and drop
     * exactly what this bon charged.
     */
    public function post(PettyCashVoucher $voucher, User $by): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $by): PettyCashVoucher {
            /*
             * lockForUpdate() is a no-op on SQLite, so the status re-read
             * below is the actual protection against a double post.
             */
            /** @var PettyCashVoucher $voucher */
            $voucher = PettyCashVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            $this->assertDraft($voucher);

            $fund = PettyCashFund::query()->with('coaAccount')->findOrFail($voucher->fund_id);

            $this->assertCustodian($fund, $by, 'memposting voucher');
            $this->assertFundActive($fund);

            // Per-bon ceiling: the refusal points big spends to the AP-bill
            // path, where a real approval chain guards them.
            if ($fund->max_voucher_amount !== null
                && $this->cents((float) $voucher->amount - (float) $fund->max_voucher_amount) > 0) {
                throw new LogicException(
                    "Voucher {$voucher->code} sebesar {$voucher->amount} melebihi batas per bon "
                    ."{$fund->max_voucher_amount} pada kas kecil {$fund->code}. Belanja sebesar ini "
                    .'ditagihkan lewat tagihan vendor (AP) dengan persetujuan, bukan lewat laci.'
                );
            }

            // A drawer cannot disburse cash it does not hold — AS OF THE BON'S
            // OWN DATE, because that is the date the journal will carry: a bon
            // back-dated 2026-05-20 against a drawer funded 2026-06-01 must
            // not borrow June's cash into May's balance sheet. Re-computed
            // here inside the transaction — never trusted from the screen.
            $balance = $this->funds->balance($fund, $voucher->voucher_date->toDateString());

            if ($this->cents((float) $voucher->amount - $balance) > 1) {
                throw new LogicException(
                    "Voucher {$voucher->code} sebesar {$voucher->amount} melebihi saldo laci "
                    ."{$fund->code} per {$voucher->voucher_date->toDateString()} ({$balance}). "
                    .'Isi ulang dananya lebih dulu.'
                );
            }

            $expenseCode = $voucher->project_id !== null
                ? $voucher->category->cogsAccountCode()
                : $voucher->category->opexAccountCode();

            // assertPeriodOpen runs inside autoPost — a bon dated in a closed
            // month is refused with the period's own message.
            $this->journals->autoPost(
                'petty_cash_voucher',
                (int) $voucher->id,
                [
                    [
                        'account_code' => $expenseCode,
                        'debit' => (float) $voucher->amount,
                        'description' => $voucher->description,
                        'project_id' => $voucher->project_id,
                    ],
                    [
                        'account_id' => (int) $fund->coa_account_id,
                        'credit' => (float) $voucher->amount,
                        'description' => "{$fund->name} — {$voucher->code}",
                    ],
                ],
                $voucher->voucher_date->toDateString(),
                "Kas kecil {$voucher->code} — {$voucher->description}",
                (int) $by->id,
            );

            if ($voucher->project_id !== null) {
                $this->projectCosts->record(
                    (int) $voucher->project_id,
                    $voucher->voucher_date->toDateString(),
                    $voucher->category->costCategory(),
                    'petty_cash_voucher',
                    (int) $voucher->id,
                    $voucher->description,
                    (float) $voucher->amount,
                    $voucher->wbs_task_id !== null ? (int) $voucher->wbs_task_id : null,
                );
            }

            $voucher->forceFill([
                'status' => PettyCashVoucherStatus::Posted,
                'posted_at' => now(),
            ])->save();

            return $voucher->refresh();
        });
    }

    /**
     * Reverse a posted bon — but never one the company has already been
     * reimbursed for.
     *
     * Once a POSTED replenishment covers the bon, the bank transfer that paid
     * it back has happened on the strength of this voucher; reversing it now
     * would leave the drawer holding money its paper does not explain. The
     * correction after that point is a finance JV, and the message says so.
     * A bon stamped by a replenishment still awaiting approval MAY be
     * cancelled — the balance drift correctly wedges that replenishment into
     * "ajukan ulang" at post time, and reject() is its exit.
     */
    public function cancel(PettyCashVoucher $voucher, User $by, string $reason): PettyCashVoucher
    {
        return DB::transaction(function () use ($voucher, $by, $reason): PettyCashVoucher {
            /** @var PettyCashVoucher $voucher */
            $voucher = PettyCashVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            $reason = trim($reason);

            if ($reason === '') {
                throw new LogicException('Alasan pembatalan wajib diisi.');
            }

            if ($voucher->status !== PettyCashVoucherStatus::Posted) {
                throw new LogicException(
                    "Voucher {$voucher->code} berstatus {$voucher->status->value}; "
                    .'hanya voucher terposting yang dapat dibatalkan.'
                );
            }

            $replenishment = $voucher->replenishmentPayment;

            if ($replenishment !== null && $replenishment->status === PaymentStatus::Posted) {
                throw new LogicException(
                    "Voucher {$voucher->code} sudah diganti oleh isi ulang {$replenishment->code} yang "
                    .'terposting; pembatalan akan membuat uang penggantian tidak berdasar. '
                    .'Koreksinya dibukukan lewat jurnal penyesuaian (JV) oleh keuangan.'
                );
            }

            $this->journals->reverseFor(
                'petty_cash_voucher',
                (int) $voucher->id,
                'petty_cash_voucher_cancellation',
                "Pembatalan voucher {$voucher->code} — {$reason}",
                (int) $by->id,
                $this->journals->reversalDate($voucher->voucher_date),
            );

            // The cost row no longer has a ledger entry behind it; a survivor
            // would put the project P&L above the GL by exactly this bon.
            $this->projectCosts->remove('petty_cash_voucher', (int) $voucher->id);

            $voucher->forceFill([
                'status' => PettyCashVoucherStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ])->save();

            return $voucher->refresh();
        });
    }

    // ------------------------------------------------------------- guards

    private function assertDraft(PettyCashVoucher $voucher): void
    {
        if ($voucher->status !== PettyCashVoucherStatus::Draft) {
            throw new LogicException("Voucher {$voucher->code} sudah {$voucher->status->value}.");
        }
    }

    private function assertAmountPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw new LogicException('Nilai voucher kas kecil harus lebih besar dari nol.');
        }
    }

    private function assertFundActive(PettyCashFund $fund): void
    {
        if (! $fund->is_active) {
            throw new LogicException("Kas kecil {$fund->code} sudah nonaktif dan tidak menerima transaksi baru.");
        }
    }

    /**
     * Strict: only the fund's custodian, no admin bypass. Reassigning
     * custodianship (fin.update on the fund) is the escape hatch, and it
     * leaves a trail.
     */
    private function assertCustodian(PettyCashFund $fund, User $by, string $action): void
    {
        if ((int) $by->id !== (int) $fund->custodian_id) {
            throw new LogicException(
                "Hanya pemegang kas kecil {$fund->code} yang dapat {$action} — "
                .'uang tunainya ada di laci pemegang, bukan di layar orang lain. '
                .'Bila pemegangnya berganti, ubah dulu pemegang pada data kas kecilnya.'
            );
        }
    }

    /** Whole-cent compare, same reason as JournalService::assertBalanced(). */
    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
