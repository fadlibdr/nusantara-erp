<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Support\SegregationOfDuties;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Enums\PaymentDirection;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PettyCashVoucherStatus;
use Modules\Finance\Enums\WithholdingType;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatementLine;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Models\PettyCashVoucher;
use Modules\Finance\Support\SettleableLiabilities;

/**
 * Bank receipts (RCV) and disbursements (PAY) with allocation to open AR
 * invoices / AP bills. Posting settles the documents and books the bank
 * journal:
 *
 *   in  => Dr Bank (bank account COA), Cr 1-1300 Piutang Usaha
 *   out => Dr 2-1100 Hutang Usaha,     Cr Bank (bank account COA)
 *
 * PENERIMAAN TERMIN DIPOTONG PAJAK. A receipt may carry WITHHOLDING lines
 * beside its allocations, because in this trade the bank never receives the
 * invoice amount: a badan-usaha owner must withhold PPh final jasa konstruksi
 * (PP 9/2022) from every progress payment, and a BUMN/government owner also
 * collects the PPN itself (wapu). The invoice is still settled in FULL — the
 * counterparty paid part of it to the state on our behalf — so the shape is
 *
 *   Dr Bank                        cash actually received
 *   Dr 1-1700 Pajak Dibayar Dimuka PPh    PPh final withheld
 *   Dr 2-1300 PPN Keluaran                PPN collected by the owner (wapu)
 *       Cr 1-1300 Piutang Usaha           the whole amount allocated
 *
 * and the guard becomes: allocations = cash + withholdings. Without it every
 * real receipt left the invoice a few percent short forever, the aging report
 * lied, and the bank reconciliation could never close.
 *
 * POTONGAN LAIN-LAIN (DENDA KETERLAMBATAN). The same identity carries one
 * NON-tax deduction kind, WithholdingType::OtherDeduction: an owner paying a
 * late termin deducts the contractual denda (1‰/day, capped 5% — boilerplate
 * in Indonesian construction contracts) from the transfer, and before this
 * kind existed that receipt was unrecordable — the invoice hung "kurang
 * bayar" forever or was patched by a manual JV outside the flow. The deduction
 * debits 7-2400 Beban Denda & Potongan Lain-lain (an expense we bear, unlike
 * the tax kinds which are prepaid assets or a discharged liability) and
 * REQUIRES a written reason in place of the certificate a tax kind carries:
 * with no statutory paper, the reason is the entire audit trail.
 *
 * PEMBAYARAN KELUAR HARUS DISETUJUI ORANG KEDUA. A disbursement walks
 * draft → submitted → approved → posted; a receipt still goes draft → posted.
 * The asymmetry is deliberate: money arriving is corroborated by a document the
 * company does not control — the bank statement the reconciliation bridge
 * matches it against — while money leaving has no corroboration at all until
 * after it has left, which is exactly the gap maker-checker exists to close.
 *
 * The ALLOCATIONS are attached at SUBMIT, not only at post. Until this package
 * a draft payment carried nothing but an amount, a date and a bank account, so
 * an approver was being asked to approve "Rp 232.545.000 dari BCA Operasional"
 * without being told which vendor bill it settles — an approval that means
 * nothing. post() therefore re-checks the body against what was approved and
 * refuses a set that has changed since.
 *
 * PELUNASAN KEWAJIBAN NON-AP. A disbursement may also settle a liability
 * ACCOUNT directly (payable_type 'gl_account', payable_id = fin_accounts.id):
 * net payroll on 2-1110, BPJS, the SSP tax remittances, the net PPN kurang
 * bayar. Before this kind existed, the Rp 166.638.981,43 of net June wages
 * sitting in 2-1110 Hutang Gaji & Upah — which nothing had ever debited — could
 * only leave the books through a hand-keyed JV, so the biggest disbursement of
 * every month bypassed the maker-checker built for exactly it. The money rides
 * the SAME vehicle: draft → submitted → approved → posted, same permissions,
 * same SegregationOfDuties, same signature comparison. What replaces a bill's
 * outstanding() is the account's posted balance through the END OF THE
 * PAYMENT'S MONTH (payroll pays 06-25 against an accrual dated 06-30, so an
 * as-at-payment-date ceiling would refuse every real payroll run), and WHICH
 * accounts qualify is Support\SettleableLiabilities — 2-1100 stays out, so a
 * gl_account row can never fake a vendor settlement past the sub-ledger
 * tie-out. One payment settles ap_bill rows OR gl_account rows, never both:
 * one disbursement mirrors one bank mutation to one beneficiary, which is what
 * BankStatementMatchService's amount ranking and the recon bridge assume.
 *
 * DanglingDocuments needs NO registry change for this kind: fin_payments is
 * already scanned in draft/submitted/approved, and gl_account allocations add
 * no new period-dated table. Recorded here so the next package does not
 * re-litigate it.
 *
 * ISI ULANG KAS KECIL (petty_cash_fund_id). A payment stamped with a fund id
 * is a drawer top-up (PAY: Dr fund / Cr bank) or a drawer return (RCV:
 * Dr bank / Cr fund) and accepts EXACTLY ONE allocation of type
 * petty_cash_fund — never mixed with bills or liability accounts, because a
 * drawer transfer is its own bank mutation. The imprest identity IS the
 * amount guard: a top-up moves exactly float − GL balance − outstanding
 * kasbon (PettyCashFundService::replenishmentDue — the advance is drawer money
 * that left as a receivable and no bon evidences it), verified at submit and
 * RE-VERIFIED inside post()'s transaction, so a bon posted (or cancelled)
 * between approval and post drifts the balance and the post is refused with
 * "ajukan ulang" — the assertApprovedForPosting philosophy applied to a
 * number instead of a set. submit() stamps the covered voucher pile
 * (replenishment_payment_id) as the frozen set the approver reviews with its
 * receipts; reject() unstamps it. Ordinary payments keep petty_cash_fund_id
 * null and every guard and message above bit-identical.
 *
 * PEMBALIKAN PEMBAYARAN TERPOSTING. reverse() is the only way out of a posted
 * payment and it is a REVERSAL, never an edit: the original journal is left
 * untouched, JournalService::reverseFor() posts its mirror, and the payment
 * lands on the terminal status Reversed. Before it existed a receipt allocated
 * to the wrong faktur locked that invoice out of cancellation for ever, because
 * ArInvoiceService::cancel() refuses any invoice with amount_paid > 0 and
 * nothing could bring amount_paid back down. What it refuses is as load-bearing
 * as what it does — see the guards on reverse() itself.
 */
class PaymentService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly PettyCashFundService $pettyCashFunds,
    ) {}

    public function create(array $data): Payment
    {
        return DB::transaction(function () use ($data): Payment {
            $payment = new Payment([
                'direction' => $data['direction'],
                'payment_date' => $data['payment_date'],
                'bank_account_id' => $data['bank_account_id'],
                'amount' => round((float) $data['amount'], 2),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                // Set only by PettyCashFundController's replenish/return —
                // deliberately NOT updatable afterwards (update() keeps it out
                // of its Arr::only), because it decides the allocation shape
                // the approver will be shown.
                'petty_cash_fund_id' => $data['petty_cash_fund_id'] ?? null,
            ]);
            $payment->status = PaymentStatus::Draft;
            $payment->save(); // HasDocumentNumber fills RCV/PAY code by direction

            return $payment;
        });
    }

    /**
     * The editable check runs INSIDE the transaction, on a locked re-read.
     *
     * Asserting on the caller's instance left the stale-instance window
     * JournalService::update() describes: a route-bound payment is read
     * several DB round-trips before the handler reaches this line, and a
     * submit or a post landing inside that window is invisible to the copy in
     * hand. The landing here is the worst of the family — post() has already
     * autoPost()ed the bank leg and bumped amount_paid on every document the
     * allocations named, so re-writing amount or payment_date afterwards
     * leaves the posted journal saying one number and the payment saying
     * another, with the bank reconciliation and the AR/AP sub-ledger
     * permanently apart. lockForUpdate() is a NO-OP on SQLite, so the re-read
     * plus the re-check is the actual protection, not the lock.
     */
    public function update(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data): Payment {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($payment);

            // Direction is fixed once the RCV/PAY number exists.
            $payment->fill(Arr::only($data, [
                'payment_date', 'bank_account_id', 'amount', 'reference', 'notes',
            ]))->save();

            return $payment->refresh();
        });
    }

    /**
     * Same stale-instance window as update(), with a landing of its own: every
     * GL and sub-ledger reader joins fin_payments, so a payment posted between
     * the route binding and this call would be soft-deleted while its journal
     * stayed in the ledger — bank cash the trial balance carries and no
     * document explains.
     */
    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($payment);

            $payment->delete();
        });
    }

    /**
     * Ajukan pembayaran keluar untuk persetujuan, lengkap dengan alokasinya.
     *
     * The allocations are validated here with the same rules post() applies —
     * approved bills or allowlisted liability accounts, no overpay past the
     * outstanding/ceiling, no mixing of the two kinds, sum equal to the amount
     * — and then STORED, so the approver reads which tagihan or kewajiban the
     * money discharges before agreeing to it, and so post() has something
     * concrete to compare the body against.
     *
     * A rejected payment may be submitted again; its previous allocations are
     * replaced rather than appended, because the clerk is correcting the same
     * disbursement, not adding a second one.
     */
    public function submit(Payment $payment, array $allocations, ?User $by = null): Payment
    {
        return DB::transaction(function () use ($payment, $allocations, $by): Payment {
            $this->assertOutgoing($payment);

            if (! $payment->status->isEditable()) {
                throw new LogicException(
                    "Pembayaran {$payment->code} sudah ".mb_strtolower($payment->status->label()).'.'
                );
            }

            $rows = $this->validateOutgoingAllocations($payment, $allocations);

            $payment->allocations()->delete();

            foreach ($rows as $row) {
                $payment->allocations()->create($row);
            }

            if ($payment->petty_cash_fund_id !== null) {
                $this->stampCoveredVouchers($payment);
            }

            $payment->forceFill(['status' => PaymentStatus::Submitted])->save();
            $this->recordApproval($payment, 'submitted', $by);

            return $payment->load('allocations');
        });
    }

    /**
     * The second pair of eyes. SegregationOfDuties refuses the person who
     * clicked Ajukan — the whole reason this stage exists.
     */
    public function approve(Payment $payment, User $by, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($payment, $by, $note): Payment {
            $this->assertOutgoing($payment);
            $this->assertAwaitingApproval($payment, 'disetujui');

            SegregationOfDuties::assertNotSubmitter($payment, $by);

            $payment->forceFill(['status' => PaymentStatus::Approved])->save();
            $this->recordApproval($payment, 'approved', $by, $note);

            return $payment->load('allocations');
        });
    }

    /**
     * Back to the clerk's desk, WITH the allocations still on it: a rejection
     * is usually "wrong bank account" or "wrong bill", and making the clerk
     * retype every line invites a second, different mistake.
     *
     * Not guarded against self-rejection, deliberately: rejecting your own
     * document moves no money and returns it to your own desk.
     */
    public function reject(Payment $payment, User $by, string $note): Payment
    {
        return DB::transaction(function () use ($payment, $by, $note): Payment {
            $this->assertOutgoing($payment);
            $this->assertRejectable($payment);

            // The frozen review set dissolves with the rejection: the bons —
            // and the settled kasbons riding the same stamp — go back to
            // "unreplenished" so the corrected top-up can freeze its own
            // (possibly larger) pile at the next submit.
            if ($payment->petty_cash_fund_id !== null) {
                PettyCashVoucher::query()
                    ->where('replenishment_payment_id', $payment->id)
                    ->update(['replenishment_payment_id' => null]);
                Kasbon::query()
                    ->where('replenishment_payment_id', $payment->id)
                    ->update(['replenishment_payment_id' => null]);
            }

            $payment->forceFill(['status' => PaymentStatus::Rejected])->save();
            $this->recordApproval($payment, 'rejected', $by, $note);

            return $payment->load('allocations');
        });
    }

    /**
     * Post the payment with its allocations and, on a receipt, the tax the
     * customer withheld:
     *
     *   allocations  = [[payable_type => 'ar_invoice'|'ap_bill'|'gl_account',
     *                    payable_id, amount, remark?], ...]
     *   withholdings = [[ar_invoice_id, type => 'pph_final'|'ppn_wapu', amount,
     *                    certificate_no?, certificate_date?], ...]
     *
     * Guards: allocations match the direction, don't overpay any document, and
     * sum to the cash received PLUS everything withheld (identical to the old
     * rule whenever nothing is withheld). Settled documents get amount_paid
     * bumped and paid_at stamped when fully paid — the withheld part settles
     * them exactly like cash, which is the whole point.
     */
    public function post(Payment $payment, array $allocations, ?int $userId = null, array $withholdings = []): Payment
    {
        return DB::transaction(function () use ($payment, $allocations, $userId, $withholdings): Payment {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $direction = $payment->direction;

            if ($direction === PaymentDirection::In) {
                $this->assertDraft($payment);
            } else {
                $this->assertApprovedForPosting($payment, $allocations);
            }

            if ($allocations === []) {
                throw new LogicException('A payment needs at least one allocation to post.');
            }

            // Receipts refuse gl_account rows entirely — a customer-advance
            // receipt (Cr 2-1400) is a real future need, but 2-1400 belongs to
            // the contract-liability engine and needs its own design (named
            // seam), not a quiet widening here.
            $expectedTypes = $direction === PaymentDirection::In
                ? [PaymentAllocation::TYPE_AR_INVOICE]
                : [PaymentAllocation::TYPE_AP_BILL, PaymentAllocation::TYPE_GL_ACCOUNT];

            // A payment stamped as a drawer top-up/return accepts ONLY its
            // fund row — a bill or invoice smuggled onto it would ride an
            // amount the imprest rule fixed, and vice versa. Ordinary payments
            // never reach this branch, so their messages stay bit-identical.
            if ($payment->petty_cash_fund_id !== null) {
                $expectedTypes = [PaymentAllocation::TYPE_PETTY_CASH_FUND];
            }

            $this->assertUnmixed($allocations);

            $payment->allocations()->delete(); // idempotent re-post of a draft
            $payment->withholdings()->delete();

            // Recorded before the allocations are settled so that a wrong bukti
            // potong is reported as such, instead of surfacing later as the
            // allocation it silently inflated.
            [$withheld, $withholdingLines] = $this->recordWithholdings($payment, $allocations, $withholdings);

            $allocated = 0.0;
            $journalLines = [];
            $byAccount = [];
            $byFund = [];

            foreach ($allocations as $input) {
                $type = (string) $input['payable_type'];
                $amount = round((float) $input['amount'], 2);

                if (! in_array($type, $expectedTypes, true)) {
                    throw new LogicException(
                        "A payment {$direction->value} can only settle ".implode(' or ', $expectedTypes).' documents.'
                    );
                }

                if ($amount <= 0) {
                    throw new LogicException('Allocation amounts must be positive.');
                }

                $journalLines[] = match ($type) {
                    PaymentAllocation::TYPE_AR_INVOICE => $this->settleInvoice($payment, (int) $input['payable_id'], $amount),
                    PaymentAllocation::TYPE_AP_BILL => $this->settleBill($payment, (int) $input['payable_id'], $amount),
                    PaymentAllocation::TYPE_PETTY_CASH_FUND => $this->settleFund($payment, (int) $input['payable_id'], $amount, $byFund),
                    default => $this->settleAccount(
                        $payment,
                        (int) $input['payable_id'],
                        $amount,
                        $input['remark'] ?? null,
                        $byAccount,
                    ),
                };

                $allocated = round($allocated + $amount, 2);
            }

            // Same 1-cent tolerance as JournalService::assertBalanced(), and
            // compared in whole cents for the same reason: a raw double
            // subtraction makes the tolerance depend on the magnitude of the
            // amounts, so small payments would be refused where large ones pass.
            if (abs((int) round(($allocated - (float) $payment->amount - $withheld) * 100)) > 1) {
                throw new LogicException($withheld > 0.0
                    ? "Alokasi ({$allocated}) harus sama dengan uang diterima ({$payment->amount}) ditambah potongan pajak ({$withheld})."
                    : "Allocations ({$allocated}) must sum to the payment amount ({$payment->amount}).");
            }

            $bankAccount = BankAccount::query()
                ->with('coaAccount')
                ->findOrFail($payment->bank_account_id);

            $bankLine = [
                'account_id' => (int) $bankAccount->coa_account_id,
                'description' => "{$bankAccount->name} — {$payment->code}",
            ];

            if ($direction === PaymentDirection::In) {
                $bankLine['debit'] = (float) $payment->amount;
            } else {
                $bankLine['credit'] = (float) $payment->amount;
            }

            $this->journals->autoPost(
                'payment',
                (int) $payment->id,
                array_merge([$bankLine], $withholdingLines, $journalLines),
                $payment->payment_date->toDateString(),
                ($direction === PaymentDirection::In ? 'Penerimaan' : 'Pembayaran')." {$payment->code}",
                $userId,
            );

            $payment->forceFill(['status' => PaymentStatus::Posted])->save();

            return $payment->load(['allocations', 'withholdings']);
        });
    }

    /**
     * Balikkan pembayaran yang sudah diposting.
     *
     * THE ONE WAY OUT of a posted payment, and the reason document
     * cancellation stopped being a dead end. ArInvoiceService::cancel() refuses
     * any invoice with amount_paid > 0 and ApBillService::cancel() refuses any
     * bill with amount_paid > 0 — correctly, because a settled document has
     * money against it — but with no reversal in existence one receipt keyed
     * against the wrong faktur locked that invoice out of cancellation
     * permanently, and its termin stayed stamped billed_at so the replacement
     * invoice could never be raised either.
     *
     * IT IS A REVERSAL, NOT AN EDIT, and every piece of that matters:
     *
     *  - the original journal is never touched; reverseFor() mirrors its LINES
     *    (so a receipt that carried Dr 1-1700 PPh and Dr 2-1300 wapu is undone
     *    exactly as it was booked, whatever shape post() chose that day);
     *  - the mirror's DATE is reversalDate()'s decision — the payment's own
     *    date while that period is open and no posted PSAK 115 run measured it,
     *    otherwise TODAY, because a reversal dropped back into a measured month
     *    makes that month's revenue negative while the next run books the
     *    catch-up with nothing to offset it;
     *  - amount_paid comes back off every document the allocations named and
     *    paid_at is cleared, which is what lets the invoice be cancelled;
     *  - the status is Reversed, terminal — NOT draft. The money moved, the
     *    bank statement will always say so, and re-posting the same document
     *    would double the bank leg.
     *
     * The allocation and withholding ROWS are kept, not deleted: they are the
     * record of what was mis-allocated, and every reader that means "this
     * payment settled something" already joins fin_payments on status = posted
     * (OutstandingAsOf, PeriodCloseService::itemSubledgerTied,
     * SettleableLiabilities::pendingAllocations), so they stop counting the
     * moment the status changes.
     */
    public function reverse(Payment $payment, User $by, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $by, $reason): Payment {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $reason = trim((string) $reason);

            if ($reason === '') {
                throw new LogicException('Alasan pembalikan pembayaran wajib diisi.');
            }

            // Re-checked on the re-read inside the transaction, not on the
            // caller's instance: lockForUpdate() is a no-op on SQLite, so this
            // is what stops two reversals of one payment posting two mirrors
            // and crediting the bank twice.
            $this->assertReversible($payment);

            $allocations = $payment->allocations()->get();

            $this->assertNothingDownstreamHasMoved($payment, $allocations);

            $this->journals->reverseFor(
                'payment',
                (int) $payment->id,
                'payment_reversal',
                "Pembalikan {$payment->code} — {$reason}",
                (int) $by->id,
                $this->journals->reversalDate($payment->payment_date),
            );

            foreach ($allocations as $allocation) {
                $this->releaseAllocation($allocation);
            }

            $payment->forceFill([
                'status' => PaymentStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $by->id,
                'reversal_reason' => $reason,
            ])->save();

            // The same core_approvals row cancel() writes on an invoice, so the
            // document history reads as one sequence rather than two.
            $this->recordApproval($payment, 'reversed', $by, $reason);

            return $payment->load(['allocations', 'withholdings']);
        });
    }

    /**
     * Only a POSTED payment has a journal to mirror.
     *
     * The already-reversed case gets its own sentence rather than falling into
     * the generic one, because "Payment PAY/2026/VIII/0007 is already reversed"
     * is the answer to a double click and the operator needs to read it as
     * "nothing more to do", not as an error.
     */
    private function assertReversible(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Reversed) {
            throw new LogicException(
                "Pembayaran {$payment->code} sudah dibalik pada "
                .($payment->reversed_at?->toDateString() ?? '-').'.'
            );
        }

        if ($payment->status !== PaymentStatus::Posted) {
            throw new LogicException(
                "Pembayaran {$payment->code} belum diposting (status: "
                .mb_strtolower($payment->status->label()).'), jadi tidak ada yang perlu dibalik — '
                .'hapus drafnya atau tolak pengajuannya.'
            );
        }
    }

    /**
     * Refuse a reversal whose consequences have already been consumed by
     * something else.
     *
     * @param  Collection<int, PaymentAllocation>  $allocations
     */
    private function assertNothingDownstreamHasMoved(Payment $payment, Collection $allocations): void
    {
        // A drawer top-up/return is not reversed, it is undone by the opposite
        // transfer. Its money did not settle a document: it moved the imprest
        // float, submit() FROZE a pile of bons and settled kasbons against it
        // (replenishment_payment_id), and the drawer has been spent from since.
        // Un-stamping that paper and pulling the cash back out would drive
        // 1-11xx negative on a day the cashier already counted. Named seam:
        // shrinking a drawer is PettyCashFundController::returnToBank().
        if ($payment->petty_cash_fund_id !== null) {
            throw new LogicException(
                "Pembayaran {$payment->code} adalah transfer kas kecil; pembalikannya adalah transfer "
                .'berlawanan arah (setoran ke bank atau isi ulang), bukan pembalikan pembayaran — '
                .'bon dan kasbon yang sudah dicap sebagai terganti tidak dapat ditarik kembali.'
            );
        }

        // A matched statement line is the bank's own word that this payment
        // cleared. Reversing underneath it leaves the reconciliation pointing
        // at a document that no longer settles anything, and the bridge's
        // residual moves with nothing to explain it. Unmatch the line first —
        // that is what BankStatementMatchService::reopen() is for.
        $matched = BankStatementLine::query()
            ->where('matched_type', BankStatementLine::MATCH_PAYMENT)
            ->where('matched_id', $payment->id)
            ->exists();

        if ($matched) {
            throw new LogicException(
                "Pembayaran {$payment->code} sudah dicocokkan dengan mutasi bank; buka dulu pencocokannya "
                .'di rekonsiliasi bank sebelum membalik pembayaran ini.'
            );
        }

        foreach ($allocations as $allocation) {
            if ($allocation->payable_type === PaymentAllocation::TYPE_AR_INVOICE) {
                $this->assertInvoiceStillReversible((int) $allocation->payable_id, $payment);

                continue;
            }

            if ($allocation->payable_type === PaymentAllocation::TYPE_AP_BILL) {
                $this->assertBillStillReversible((int) $allocation->payable_id, $payment);
            }
        }
    }

    /**
     * Retensi yang sudah dicairkan adalah uang yang benar-benar masuk lewat
     * jurnal terpisah (ArRetentionService::release debits the bank and credits
     * 1-1350). Un-paying the termin that RAISED that retention would leave the
     * released cash standing on an invoice the aging report has just re-opened
     * — and the invoice could not be cancelled afterwards either, because
     * ArInvoiceService::cancel() refuses a released retention for the same
     * reason. Same guard, same sentence, one step earlier in the chain.
     */
    private function assertInvoiceStillReversible(int $invoiceId, Payment $payment): void
    {
        /** @var ArInvoice|null $invoice */
        $invoice = ArInvoice::query()->find($invoiceId);

        if ($invoice === null) {
            return; // nothing left to give the money back to
        }

        if ($invoice->status !== DocumentStatus::Approved) {
            throw new LogicException(
                "Invoice {$invoice->code} berstatus {$invoice->status->value}; pembayaran {$payment->code} "
                .'tidak dapat dibalik terhadap dokumen yang sudah berpindah status.'
            );
        }

        if ($invoice->retentions()->where('released', true)->exists()) {
            throw new LogicException(
                "Retensi dari invoice {$invoice->code} sudah dicairkan; pembayaran {$payment->code} "
                .'tidak dapat dibalik.'
            );
        }
    }

    private function assertBillStillReversible(int $billId, Payment $payment): void
    {
        /** @var ApBill|null $bill */
        $bill = ApBill::query()->find($billId);

        if ($bill === null) {
            return;
        }

        if ($bill->status !== DocumentStatus::Approved) {
            throw new LogicException(
                "Tagihan {$bill->code} berstatus {$bill->status->value}; pembayaran {$payment->code} "
                .'tidak dapat dibalik terhadap dokumen yang sudah berpindah status.'
            );
        }
    }

    /**
     * Give one allocation's money back to the document it settled.
     *
     * amount_paid never goes below zero (max(0, …)) and paid_at is cleared the
     * moment the document stops being fully paid — the pair of columns
     * settleInvoice()/settleBill() set, unset by exactly the same rule so a
     * reversed-then-re-paid document reads identically to one paid once.
     *
     * A gl_account row needs nothing: its ceiling is derived live from the
     * posted GL (SettleableLiabilities::ceiling), which the mirror journal has
     * already restored.
     */
    private function releaseAllocation(PaymentAllocation $allocation): void
    {
        $amount = round((float) $allocation->amount, 2);

        if ($allocation->payable_type === PaymentAllocation::TYPE_AR_INVOICE) {
            /** @var ArInvoice|null $invoice */
            $invoice = ArInvoice::query()->whereKey($allocation->payable_id)->lockForUpdate()->first();

            if ($invoice === null) {
                return;
            }

            $paid = round(max(0.0, (float) $invoice->amount_paid - $amount), 2);

            $invoice->forceFill([
                'amount_paid' => $paid,
                'paid_at' => $paid >= (float) $invoice->total - 0.01 ? $invoice->paid_at : null,
            ])->save();

            return;
        }

        if ($allocation->payable_type === PaymentAllocation::TYPE_AP_BILL) {
            /** @var ApBill|null $bill */
            $bill = ApBill::query()->whereKey($allocation->payable_id)->lockForUpdate()->first();

            if ($bill === null) {
                return;
            }

            $paid = round(max(0.0, (float) $bill->amount_paid - $amount), 2);

            $bill->forceFill([
                'amount_paid' => $paid,
                'paid_at' => $paid >= (float) $bill->total_payable - 0.01 ? $bill->paid_at : null,
            ])->save();
        }
    }

    /**
     * Validate and store the tax withheld out of this receipt.
     *
     * @param  array  $allocations  the raw allocations of the same posting — a
     *                              withholding must belong to an invoice this
     *                              payment actually settles, otherwise it is a
     *                              bukti potong for someone else's faktur
     * @return array{0: float, 1: array<int, array<string, mixed>>} total withheld and its journal lines
     */
    private function recordWithholdings(Payment $payment, array $allocations, array $withholdings): array
    {
        if ($withholdings === []) {
            return [0.0, []];
        }

        // A disbursement withholds nothing FROM us: PPh we withhold from a
        // vendor is already carried on the bill (2-12xx), and letting it be
        // entered here as well would credit the liability twice.
        if ($payment->direction !== PaymentDirection::In) {
            throw new LogicException(
                "Potongan pajak hanya berlaku pada penerimaan; pembayaran keluar {$payment->code} tidak dapat membawa potongan."
            );
        }

        $allocatedByInvoice = [];

        foreach ($allocations as $allocation) {
            if (($allocation['payable_type'] ?? null) !== PaymentAllocation::TYPE_AR_INVOICE) {
                continue;
            }

            $invoiceId = (int) $allocation['payable_id'];
            $allocatedByInvoice[$invoiceId] = round(
                ($allocatedByInvoice[$invoiceId] ?? 0.0) + round((float) $allocation['amount'], 2),
                2,
            );
        }

        $total = 0.0;
        $lines = [];
        $withheldByInvoice = [];

        foreach ($withholdings as $input) {
            $type = WithholdingType::tryFrom((string) ($input['type'] ?? ''));

            if ($type === null) {
                $known = implode(', ', WithholdingType::values());

                throw new LogicException("Jenis potongan pajak tidak dikenal; gunakan salah satu dari: {$known}.");
            }

            $amount = round((float) ($input['amount'] ?? 0), 2);

            if ($amount <= 0) {
                throw new LogicException('Nilai potongan pajak harus lebih besar dari nol.');
            }

            $certificateNo = trim((string) ($input['certificate_no'] ?? ''));

            if ($certificateNo === '' && $type->requiresCertificate()) {
                throw new LogicException(
                    "Nomor {$type->certificateLabel()} wajib diisi untuk potongan {$type->label()}."
                );
            }

            $reason = trim((string) ($input['reason'] ?? ''));

            // A non-tax deduction has no bukti potong; the written reason IS
            // its audit trail. Without it the auditor finds a 7-2400 debit
            // nobody can trace to a contract clause — refused here, before
            // anything is settled, same discipline as the certificate rule.
            if ($reason === '' && $type->requiresReason()) {
                throw new LogicException(
                    'Alasan potongan lain-lain wajib diisi — sebutkan dasarnya '
                    .'(mis. "denda keterlambatan 10 hari × 1‰, pasal sekian kontrak").'
                );
            }

            $invoiceId = (int) ($input['ar_invoice_id'] ?? 0);

            if (! array_key_exists($invoiceId, $allocatedByInvoice)) {
                throw new LogicException(
                    'Potongan pajak harus mengacu pada invoice yang dilunasi oleh pembayaran ini.'
                );
            }

            /** @var ArInvoice $invoice */
            $invoice = ArInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            $withheldByInvoice[$invoiceId] = round(($withheldByInvoice[$invoiceId] ?? 0.0) + $amount, 2);
            $invoiceWithheld = $withheldByInvoice[$invoiceId];

            if ($invoiceWithheld > $invoice->outstanding() + 0.01) {
                throw new LogicException(
                    "Potongan pajak {$invoiceWithheld} melebihi sisa tagihan {$invoice->outstanding()} pada {$invoice->code}."
                );
            }

            // The withheld part is a slice OF the settlement, never an extra on
            // top of it: allocate the gross invoice value, not the net transfer.
            if ($invoiceWithheld > $allocatedByInvoice[$invoiceId] + 0.01) {
                throw new LogicException(
                    "Potongan pajak {$invoiceWithheld} melebihi nilai yang dialokasikan ({$allocatedByInvoice[$invoiceId]}) ke {$invoice->code}."
                );
            }

            $certificateDate = ($input['certificate_date'] ?? null) ?: null;

            $payment->withholdings()->create([
                'ar_invoice_id' => $invoice->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason !== '' ? $reason : null,
                'certificate_no' => $certificateNo !== '' ? $certificateNo : null,
                'certificate_date' => $certificateDate,
            ]);

            $lines[] = [
                'account_code' => $type->accountCode(),
                'debit' => $amount,
                // A reasoned deduction narrates its reason; a tax kind narrates
                // its certificate — each line carries the evidence its kind has.
                'description' => $type->requiresReason()
                    ? "{$type->label()} {$invoice->code} — {$reason}"
                    : ($certificateNo !== ''
                        ? "{$type->label()} {$invoice->code} — {$type->certificateLabel()} {$certificateNo}"
                        : "{$type->label()} {$invoice->code}"),
                'project_id' => $invoice->project_id,
            ];

            $total = round($total + $amount, 2);
        }

        return [$total, $lines];
    }

    /**
     * @return array the credit journal line (Cr Piutang Usaha) for this allocation
     */
    private function settleInvoice(Payment $payment, int $invoiceId, float $amount): array
    {
        /** @var ArInvoice $invoice */
        $invoice = ArInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

        if ($invoice->status !== DocumentStatus::Approved) {
            throw new LogicException("Invoice {$invoice->code} is not approved; it cannot receive payments.");
        }

        if ($amount > $invoice->outstanding() + 0.01) {
            throw new LogicException(
                "Allocation {$amount} exceeds the outstanding {$invoice->outstanding()} on {$invoice->code}."
            );
        }

        $paid = round((float) $invoice->amount_paid + $amount, 2);

        $invoice->forceFill([
            'amount_paid' => $paid,
            'paid_at' => $paid >= (float) $invoice->total - 0.01
                ? $payment->payment_date->toDateString()
                : null,
        ])->save();

        $payment->allocations()->create([
            'payable_type' => PaymentAllocation::TYPE_AR_INVOICE,
            'payable_id' => $invoice->id,
            'amount' => $amount,
        ]);

        return [
            'account_code' => '1-1300',
            'credit' => $amount,
            'description' => "Pelunasan {$invoice->code}",
            'project_id' => $invoice->project_id,
        ];
    }

    /**
     * @return array the debit journal line (Dr Hutang Usaha) for this allocation
     */
    private function settleBill(Payment $payment, int $billId, float $amount): array
    {
        /** @var ApBill $bill */
        $bill = ApBill::query()->whereKey($billId)->lockForUpdate()->firstOrFail();

        if ($bill->status !== DocumentStatus::Approved) {
            throw new LogicException("Bill {$bill->code} is not approved; it cannot be paid.");
        }

        if ($amount > $bill->outstanding() + 0.01) {
            throw new LogicException(
                "Allocation {$amount} exceeds the outstanding {$bill->outstanding()} on {$bill->code}."
            );
        }

        $paid = round((float) $bill->amount_paid + $amount, 2);

        $bill->forceFill([
            'amount_paid' => $paid,
            'paid_at' => $paid >= (float) $bill->total_payable - 0.01
                ? $payment->payment_date->toDateString()
                : null,
        ])->save();

        $payment->allocations()->create([
            'payable_type' => PaymentAllocation::TYPE_AP_BILL,
            'payable_id' => $bill->id,
            'amount' => $amount,
        ]);

        return [
            'account_code' => '2-1100',
            'debit' => $amount,
            'description' => "Pembayaran {$bill->code}",
            'project_id' => $bill->project_id,
        ];
    }

    /**
     * Settle a non-AP liability straight on its GL account.
     *
     * The ceiling is re-derived HERE, inside post()'s transaction, against
     * posted journals only: lockForUpdate() is a no-op on SQLite, so this
     * re-read is the actual race protection. Two approved payments against the
     * same 2-1110 balance both passed submit; the first posts and its debit
     * shrinks the ceiling, so the second is refused on this line — and
     * reject() accepting Approved is the exit for the one that lost.
     *
     * @param  array<int, float>  $byAccount  running per-account totals across
     *                                        this posting — two rows against
     *                                        one account are summed before the
     *                                        ceiling check, same as the
     *                                        two-lines-one-bill rule
     * @return array the debit journal line (Dr the named liability) for this allocation
     */
    private function settleAccount(Payment $payment, int $accountId, float $amount, ?string $remark, array &$byAccount): array
    {
        $account = Account::query()->find($accountId);

        if ($account === null) {
            throw new LogicException('Akun kewajiban yang dituju tidak ditemukan di bagan akun.');
        }

        SettleableLiabilities::assertSettleable($account);

        $end = $payment->payment_date->toImmutable()->endOfMonth()->toDateString();
        $ceiling = SettleableLiabilities::ceiling($account, $payment->payment_date->toDateString());

        $byAccount[$account->id] = round(($byAccount[$account->id] ?? 0.0) + $amount, 2);

        // Whole-cent comparison, same reason as JournalService::assertBalanced.
        // The message names the window it consulted: a liability accrued in a
        // LATER month than the payment is economically a prepayment, and the
        // operator has to see which balance said no.
        if ((int) round(($byAccount[$account->id] - $ceiling) * 100) > 1) {
            throw new LogicException(
                "Alokasi {$byAccount[$account->id]} melebihi saldo {$account->code} {$account->name} "
                ."per {$end} ({$ceiling}) — jurnal terposting sampai akhir bulan pembayaran."
            );
        }

        $remark = trim((string) $remark);

        $payment->allocations()->create([
            'payable_type' => PaymentAllocation::TYPE_GL_ACCOUNT,
            'payable_id' => $account->id,
            'amount' => $amount,
            'remark' => $remark !== '' ? $remark : null,
        ]);

        // No project_id, deliberately: project attribution of wages happened
        // at accrual time (PayrollPostingService books one debit line per
        // project, ProjectCostService carries it into the cost ledger).
        // Settling 2-1110 afterwards is a balance-sheet event.
        return [
            'account_id' => (int) $account->id,
            'debit' => $amount,
            'description' => $remark !== '' ? $remark : "Pelunasan {$account->name}",
        ];
    }

    /**
     * Move the drawer leg of a fund top-up (PAY: Dr fund) or return (RCV:
     * Cr fund), re-deriving the imprest amount INSIDE post()'s transaction.
     *
     * lockForUpdate() is a no-op on SQLite, so this re-read of the posted GL
     * is the actual protection: a bon posted — or cancelled — between the
     * approval and this moment changes the drawer balance, the approved
     * amount no longer equals float − balance, and the post is refused. The
     * approver then re-reads the CURRENT pile on a fresh submission;
     * reject() accepting Approved is the wedged payment's exit.
     *
     * @param  array<int, float>  $byFund  running per-fund totals across this
     *                                     posting — the guard reads the posted
     *                                     GL, which this journal has not
     *                                     reached yet, so two Rp 3.000.000
     *                                     rows against a Rp 3.000.000 drawer
     *                                     each saw a full drawer and posted
     *                                     Cr fund 6.000.000 together, driving
     *                                     the drawer to −3.000.000 while the
     *                                     bank was debited cash it never held.
     *                                     Same treatment as settleAccount()'s
     *                                     $byAccount.
     * @return array the fund-account journal line for this allocation
     */
    private function settleFund(Payment $payment, int $fundId, float $amount, array &$byFund): array
    {
        if ((int) $payment->petty_cash_fund_id !== $fundId) {
            throw new LogicException(
                'Alokasi kas kecil harus menunjuk dana yang sama dengan pembayarannya.'
            );
        }

        /** @var PettyCashFund $fund */
        $fund = PettyCashFund::query()->findOrFail($fundId);

        $balance = $this->pettyCashFunds->balance($fund);
        $byFund[$fund->id] = round(($byFund[$fund->id] ?? 0.0) + $amount, 2);

        if ($payment->direction === PaymentDirection::Out) {
            // Asked of the service, which subtracts the OUTSTANDING KASBON:
            // an advance still in a mandor's pocket is drawer money that left
            // as a receivable, evidenced by no bon, and reimbursing it hands
            // the drawer working capital past its authorised float. See
            // PettyCashFundService::replenishmentDue().
            $due = $this->pettyCashFunds->replenishmentDue($fund);

            // Whole-cent, same tolerance discipline as assertBalanced(). The
            // accumulated total is what must hit the imprest amount — in
            // practice validateFundAllocations() pins a top-up to ONE row, so
            // for the row that exists this is the same comparison as before.
            if (abs((int) round(($byFund[$fund->id] - $due) * 100)) > 1) {
                throw new LogicException(
                    "Isi ulang kas kecil {$fund->code} harus tepat sebesar float dikurangi saldo laci dan "
                    ."kasbon beredar: {$fund->float_amount} − {$balance} − "
                    .$this->pettyCashFunds->outstandingKasbonTotal($fund)." = {$due}, bukan {$byFund[$fund->id]}. "
                    .'Saldo laci berubah sejak disetujui — ajukan ulang dengan jumlah yang baru.'
                );
            }

            $payment->allocations()->create([
                'payable_type' => PaymentAllocation::TYPE_PETTY_CASH_FUND,
                'payable_id' => $fund->id,
                'amount' => $amount,
            ]);

            return [
                'account_id' => (int) $fund->coa_account_id,
                'debit' => $amount,
                'description' => "Isi ulang kas kecil {$fund->name} — {$payment->code}",
            ];
        }

        // Drawer -> bank (shrinking or closing a fund): the drawer cannot
        // return cash it does not hold — summed across THIS posting's rows,
        // because a return posts straight from draft with no submit-time
        // validation to pin its row count. Posts from draft like every RCV —
        // the bank statement corroborates money arriving.
        //
        // READ AS AT THE PAYMENT'S OWN DATE, because that is the date the
        // journal this method feeds will carry (post() passes
        // payment_date->toDateString() to autoPost). Against the UNDATED
        // balance a clerk could mint the return today, edit the draft's date
        // back to 2026-05-20 — before the drawer was funded on 2026-06-01 —
        // and post Rp 3.000.000 out of a drawer that held nothing in May: the
        // May balance sheet then reported Kas Kecil at −3.000.000 and Bank at
        // 3.000.000 more than it received. Same rule, same reason, as
        // PettyCashVoucherService (voucher_date) and KasbonService
        // (advance_date). The Out branch keeps the undated read above on
        // purpose: a top-up must restore the drawer to its float as it stands
        // NOW, and it cannot drive the drawer negative in any month.
        $dated = $this->pettyCashFunds->balance($fund, $payment->payment_date->toDateString());

        if ((int) round(($byFund[$fund->id] - $dated) * 100) > 1) {
            throw new LogicException(
                "Setoran kas kecil {$fund->code} ke bank sebesar {$byFund[$fund->id]} melebihi saldo laci "
                ."per {$payment->payment_date->toDateString()} ({$dated})."
            );
        }

        $payment->allocations()->create([
            'payable_type' => PaymentAllocation::TYPE_PETTY_CASH_FUND,
            'payable_id' => $fund->id,
            'amount' => $amount,
        ]);

        return [
            'account_id' => (int) $fund->coa_account_id,
            'credit' => $amount,
            'description' => "Setoran kas kecil {$fund->name} ke bank — {$payment->code}",
        ];
    }

    /**
     * A receipt still posts straight from draft, so this is the RCV guard and
     * the message every existing caller already knows.
     */
    private function assertDraft(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Draft) {
            throw new LogicException("Payment {$payment->code} is already {$payment->status->value}.");
        }
    }

    /**
     * Editable means draft OR rejected. A rejected payment has to be
     * correctable — otherwise the only way to act on a rejection is to delete
     * the document and lose the reason it was rejected for.
     */
    private function assertEditable(Payment $payment): void
    {
        if (! $payment->status->isEditable()) {
            throw new LogicException("Payment {$payment->code} is already {$payment->status->value}.");
        }
    }

    private function assertOutgoing(Payment $payment): void
    {
        if ($payment->direction === PaymentDirection::In) {
            throw new LogicException(
                "Penerimaan {$payment->code} tidak melalui tahap persetujuan; langsung diposting."
            );
        }
    }

    private function assertAwaitingApproval(Payment $payment, string $action): void
    {
        if ($payment->status !== PaymentStatus::Submitted) {
            throw new LogicException(
                "Pembayaran {$payment->code} belum diajukan, jadi belum bisa {$action} "
                .'(status: '.mb_strtolower($payment->status->label()).').'
            );
        }
    }

    /**
     * Rejectable = submitted OR approved — wider than approvable, on purpose.
     *
     * An APPROVED disbursement can become permanently unpostable: its bill
     * gets cancelled (the vendor double-billed BIL/2026/VII/0002), or its date
     * lands in a period that closed after the approval. post(), update(),
     * delete() and submit() all rightly refuse an approved payment, so
     * without this exit the document was wedged forever — and, because
     * DanglingDocuments counts approved payments as a hard block, it wedged
     * its whole fiscal month with it. Rejecting moves no money, returns the
     * document to `rejected` (editable, re-submittable, deletable), and the
     * core_approvals trail keeps both the approval and this reversal.
     *
     * approve() stays Submitted-only: widening THAT would re-approve an
     * already-approved payment and stamp a second authority on one act.
     */
    private function assertRejectable(Payment $payment): void
    {
        if (! in_array($payment->status, [PaymentStatus::Submitted, PaymentStatus::Approved], true)) {
            throw new LogicException(
                "Pembayaran {$payment->code} belum diajukan atau disetujui, jadi belum bisa ditolak "
                .'(status: '.mb_strtolower($payment->status->label()).').'
            );
        }
    }

    /**
     * A disbursement may only be posted on an approval that covers THIS body.
     *
     * Comparing the set — bill and amount, order-insensitive — is what stops
     * the approval being a rubber stamp on a number: without it a clerk could
     * get Rp 111.000.000 to PT Semen approved and then post the same
     * Rp 111.000.000 against a bill from a company they own.
     */
    private function assertApprovedForPosting(Payment $payment, array $allocations): void
    {
        if ($payment->status === PaymentStatus::Posted) {
            throw new LogicException("Payment {$payment->code} is already {$payment->status->value}.");
        }

        if ($payment->status !== PaymentStatus::Approved) {
            throw new LogicException("Pembayaran {$payment->code} belum disetujui, jadi belum boleh diposting.");
        }

        $approved = $payment->allocations()->get()
            ->map(fn (PaymentAllocation $allocation): array => [
                'payable_type' => (string) $allocation->payable_type,
                'payable_id' => (int) $allocation->payable_id,
                'amount' => (float) $allocation->amount,
            ])->all();

        if ($this->allocationSignature($approved) !== $this->allocationSignature($allocations)) {
            throw new LogicException(
                "Alokasi pembayaran {$payment->code} berbeda dari yang disetujui. "
                .'Ajukan ulang bila alokasinya berubah.'
            );
        }
    }

    /**
     * Order-insensitive fingerprint of an allocation set. Amounts are compared
     * as cents, so 111000000 and 111000000.004 are the same allocation and
     * 111000000.01 is not.
     *
     * gl_account rows fit the same type#id@cents encoding unchanged. `remark`
     * stays OUT of the signature deliberately: the approver approves account
     * and amount — the money — while remark is a memo like notes, and blocking
     * a post over an edited NTPN string would wedge real payments for nothing.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function allocationSignature(array $rows): string
    {
        $keys = array_map(
            static fn (array $row): string => sprintf(
                '%s#%d@%d',
                (string) ($row['payable_type'] ?? ''),
                (int) ($row['payable_id'] ?? 0),
                (int) round(((float) ($row['amount'] ?? 0)) * 100),
            ),
            $rows,
        );

        sort($keys);

        return implode('|', $keys);
    }

    /**
     * The same rules post() enforces, applied without settling anything, so a
     * bad allocation is refused when it is typed rather than after an approver
     * has already agreed to it.
     *
     * For gl_account rows the submit-time ceiling additionally subtracts other
     * UNPOSTED (submitted|approved) payments' gl_account allocations on the
     * same account: two payments racing for one 2-1110 balance would both pass
     * a GL-only check, and the doomed one should be refused before an approver
     * signs it rather than wedge at post.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateOutgoingAllocations(Payment $payment, array $allocations): array
    {
        if ($allocations === []) {
            throw new LogicException(
                "Pembayaran keluar {$payment->code} harus dialokasikan ke minimal satu tagihan vendor "
                .'atau kewajiban non-AP.'
            );
        }

        // A drawer top-up has its own shape — one fund row, imprest amount —
        // and never mixes with bills; validated apart so the ordinary path
        // below keeps its rules and messages untouched.
        if ($payment->petty_cash_fund_id !== null) {
            return $this->validateFundAllocations($payment, $allocations);
        }

        $this->assertUnmixed($allocations);

        $rows = [];
        $byBill = [];
        $byAccount = [];
        $allocated = 0.0;

        foreach ($allocations as $input) {
            $type = (string) ($input['payable_type'] ?? '');
            $amount = round((float) ($input['amount'] ?? 0), 2);

            if (! in_array($type, [PaymentAllocation::TYPE_AP_BILL, PaymentAllocation::TYPE_GL_ACCOUNT], true)) {
                throw new LogicException(
                    'A payment out can only settle '.PaymentAllocation::TYPE_AP_BILL
                    .' or '.PaymentAllocation::TYPE_GL_ACCOUNT.' documents.'
                );
            }

            if ($amount <= 0) {
                throw new LogicException('Allocation amounts must be positive.');
            }

            if ($type === PaymentAllocation::TYPE_GL_ACCOUNT) {
                $rows[] = $this->validateAccountAllocation($payment, $input, $amount, $byAccount);
                $allocated = round($allocated + $amount, 2);

                continue;
            }

            $billId = (int) ($input['payable_id'] ?? 0);

            /** @var ApBill $bill */
            $bill = ApBill::query()->findOrFail($billId);

            if ($bill->status !== DocumentStatus::Approved) {
                throw new LogicException("Bill {$bill->code} is not approved; it cannot be paid.");
            }

            // Summed per bill: two lines of Rp 60 juta each against one Rp 100
            // juta bill overpay it together even though neither does alone.
            $byBill[$billId] = round(($byBill[$billId] ?? 0.0) + $amount, 2);

            if ($byBill[$billId] > $bill->outstanding() + 0.01) {
                throw new LogicException(
                    "Allocation {$byBill[$billId]} exceeds the outstanding {$bill->outstanding()} on {$bill->code}."
                );
            }

            $allocated = round($allocated + $amount, 2);

            $rows[] = [
                'payable_type' => PaymentAllocation::TYPE_AP_BILL,
                'payable_id' => $billId,
                'amount' => $amount,
            ];
        }

        // Same whole-cent comparison as post(), for the same reason: a raw
        // double subtraction makes the tolerance depend on the magnitude.
        if (abs((int) round(($allocated - (float) $payment->amount) * 100)) > 1) {
            throw new LogicException(
                "Allocations ({$allocated}) must sum to the payment amount ({$payment->amount})."
            );
        }

        return $rows;
    }

    /**
     * One gl_account row, validated for submit: allowlisted account, and the
     * per-account sum must fit under the posted-GL ceiling MINUS what other
     * unposted payments have already claimed against the same account.
     *
     * @param  array<int, float>  $byAccount  running per-account totals of this set
     * @return array{payable_type: string, payable_id: int, amount: float, remark: ?string}
     */
    private function validateAccountAllocation(Payment $payment, array $input, float $amount, array &$byAccount): array
    {
        $account = Account::query()->find((int) ($input['payable_id'] ?? 0));

        if ($account === null) {
            throw new LogicException('Akun kewajiban yang dituju tidak ditemukan di bagan akun.');
        }

        SettleableLiabilities::assertSettleable($account);

        $end = $payment->payment_date->toImmutable()->endOfMonth()->toDateString();
        $pending = SettleableLiabilities::pendingAllocations((int) $account->id, (int) $payment->id);
        $ceiling = round(
            SettleableLiabilities::ceiling($account, $payment->payment_date->toDateString()) - $pending,
            2,
        );

        $byAccount[$account->id] = round(($byAccount[$account->id] ?? 0.0) + $amount, 2);

        if ((int) round(($byAccount[$account->id] - $ceiling) * 100) > 1) {
            throw new LogicException(
                "Alokasi {$byAccount[$account->id]} melebihi saldo {$account->code} {$account->name} "
                ."per {$end} ({$ceiling})"
                .($pending > 0.0
                    ? " — sudah dikurangi {$pending} yang menunggu pada pembayaran lain yang belum diposting."
                    : ' — jurnal terposting sampai akhir bulan pembayaran.')
            );
        }

        $remark = trim((string) ($input['remark'] ?? ''));

        return [
            'payable_type' => PaymentAllocation::TYPE_GL_ACCOUNT,
            'payable_id' => (int) $account->id,
            'amount' => $amount,
            'remark' => $remark !== '' ? $remark : null,
        ];
    }

    /**
     * The submit-time twin of settleFund(): one row, the fund the payment is
     * stamped with, the imprest amount, equal to the payment amount. Applied
     * when the allocations are TYPED so a wrong top-up is refused before an
     * approver ever signs it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateFundAllocations(Payment $payment, array $allocations): array
    {
        if (count($allocations) !== 1) {
            throw new LogicException(
                "Pembayaran {$payment->code} adalah isi ulang kas kecil: alokasinya tepat SATU baris "
                .'petty_cash_fund, tanpa tagihan vendor atau kewajiban lain — transfer laci adalah '
                .'satu mutasi bank tersendiri.'
            );
        }

        $input = $allocations[0];
        $type = (string) ($input['payable_type'] ?? '');
        $amount = round((float) ($input['amount'] ?? 0), 2);

        if ($type !== PaymentAllocation::TYPE_PETTY_CASH_FUND) {
            throw new LogicException(
                "Pembayaran {$payment->code} adalah isi ulang kas kecil dan hanya menerima alokasi "
                .PaymentAllocation::TYPE_PETTY_CASH_FUND.'.'
            );
        }

        if ((int) ($input['payable_id'] ?? 0) !== (int) $payment->petty_cash_fund_id) {
            throw new LogicException('Alokasi kas kecil harus menunjuk dana yang sama dengan pembayarannya.');
        }

        if ($amount <= 0) {
            throw new LogicException('Allocation amounts must be positive.');
        }

        /** @var PettyCashFund $fund */
        $fund = PettyCashFund::query()->findOrFail((int) $payment->petty_cash_fund_id);

        if (! $fund->is_active) {
            throw new LogicException("Kas kecil {$fund->code} sudah nonaktif dan tidak dapat diisi ulang.");
        }

        $balance = $this->pettyCashFunds->balance($fund);
        // Same one home as settleFund()'s post-time re-check, so submit and
        // post can never quote different numbers: float − saldo laci − kasbon
        // beredar.
        $due = $this->pettyCashFunds->replenishmentDue($fund);
        $kasbon = $this->pettyCashFunds->outstandingKasbonTotal($fund);

        if ($due <= 0) {
            throw new LogicException(
                "Kas kecil {$fund->code} sudah memegang {$balance} dari float {$fund->float_amount}"
                .($kasbon > 0 ? " (kasbon beredar {$kasbon})" : '')
                .'; tidak ada yang perlu diisi ulang.'
            );
        }

        if (abs((int) round(($amount - $due) * 100)) > 1) {
            throw new LogicException(
                "Isi ulang kas kecil {$fund->code} harus tepat sebesar float dikurangi saldo laci dan "
                ."kasbon beredar: {$fund->float_amount} − {$balance} − {$kasbon} = {$due}, bukan {$amount}."
            );
        }

        if (abs((int) round(($amount - (float) $payment->amount) * 100)) > 1) {
            throw new LogicException(
                "Allocations ({$amount}) must sum to the payment amount ({$payment->amount})."
            );
        }

        return [[
            'payable_type' => PaymentAllocation::TYPE_PETTY_CASH_FUND,
            'payable_id' => (int) $fund->id,
            'amount' => $amount,
        ]];
    }

    /**
     * Freeze the review set: every posted, not-yet-stamped bon of the fund —
     * and every SETTLED, not-yet-stamped kasbon, whose receipts live in
     * fin_kasbon_lines instead of a voucher — is stamped with THIS payment,
     * so the approver reads exactly which paper the bank transfer pays back.
     * Without the kasbon half, a Rp 1.000.000 top-up covering Rp 200.000 of
     * bons plus Rp 800.000 of settled-kasbon receipts showed the approver
     * Rp 200.000 of evidence for Rp 1.000.000 of money. The stamp is evidence
     * for the reviewer, not arithmetic — the amount is pinned by the imprest
     * rule, which also covers kasbon cash still in employees' pockets.
     *
     * whereNull on purpose (raw stamp, not the "reimbursed" filter): the
     * frozen-set question is "which pile does THIS submission claim", and a
     * pile already claimed by another unposted payment stays that payment's.
     */
    private function stampCoveredVouchers(Payment $payment): void
    {
        if ($payment->direction !== PaymentDirection::Out) {
            return; // a drawer RETURN reimburses nothing
        }

        PettyCashVoucher::query()
            ->where('fund_id', $payment->petty_cash_fund_id)
            ->where('status', PettyCashVoucherStatus::Posted->value)
            ->whereNull('replenishment_payment_id')
            ->update(['replenishment_payment_id' => $payment->id]);

        Kasbon::query()
            ->where('fund_id', $payment->petty_cash_fund_id)
            ->where('status', KasbonStatus::Settled->value)
            ->whereNull('replenishment_payment_id')
            ->update(['replenishment_payment_id' => $payment->id]);
    }

    /**
     * One payment settles vendor bills OR non-AP liability accounts, never
     * both. One disbursement mirrors one bank mutation to one beneficiary — a
     * transfer to BPJS never shares a mutation with a vendor transfer — and
     * keeping it 1:1 preserves BankStatementMatchService's amount ranking and
     * the recon bridge's one-payment-one-line story.
     */
    private function assertUnmixed(array $allocations): void
    {
        $types = array_map(
            static fn (array $input): string => (string) ($input['payable_type'] ?? ''),
            $allocations,
        );

        if (in_array(PaymentAllocation::TYPE_AP_BILL, $types, true)
            && in_array(PaymentAllocation::TYPE_GL_ACCOUNT, $types, true)) {
            throw new LogicException(
                'Satu pembayaran melunasi tagihan vendor ATAU kewajiban non-AP, tidak keduanya — '
                .'pisahkan sesuai mutasi banknya.'
            );
        }
    }

    /**
     * The same core_approvals row and the same event every Approvable document
     * writes, so a payment appears in the approval trail and reaches the
     * fin.approve holders through the listener that already exists.
     */
    private function recordApproval(Payment $payment, string $action, ?User $by, ?string $note = null): void
    {
        $payment->approvals()->create([
            'action' => $action,
            'user_id' => $by?->id,
            'note' => $note,
        ]);

        DocumentTransitioned::dispatch($payment, $action, $by, $note);
    }
}
