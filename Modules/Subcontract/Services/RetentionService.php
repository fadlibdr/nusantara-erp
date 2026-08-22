<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Services\JournalService;
use Modules\Subcontract\Models\RetentionRelease;
use Modules\Subcontract\Models\Subcontract;

/**
 * Retensi: every approved opname withholds retention_pct of the period DPP.
 * The accumulated balance is paid back (released) after masa pemeliharaan,
 * usually in one or two tranches against a BAST.
 *
 * THE MONEY HAS TO MOVE IN THE LEDGER TOO. Withholding is booked by
 * ApBillService when the opname bill is approved (Cr 2-1500 Hutang Retensi
 * Subkon); releasing it is booked here, and until it was, no document in the
 * system ever debited 2-1500. The consequences were not cosmetic: the balance
 * sheet reported retention owed to subcontractors that had already been let go,
 * the SPK screen reported the opposite, and the released money could not be
 * disbursed at all, because PaymentService settles ar_invoice and ap_bill rows
 * and a scm_retention_releases row is neither.
 *
 * So a release does two things at once — it settles the retention liability and
 * turns it into an ordinary payable:
 *
 *   Dr 2-1500 Hutang Retensi Subkon    jumlah dilepas
 *       Cr 2-1100 Hutang Usaha         jumlah dilepas
 *
 * carried by an approved AP bill, which is what makes the payment module able
 * to pay it with no change to Finance whatsoever.
 */
class RetentionService
{
    /** Retention withheld from subcontractors, owed back on release. */
    private const SUBCON_RETENTION_ACCOUNT = '2-1500';

    /** Hutang Usaha — what the released retention becomes until it is paid. */
    private const TRADE_PAYABLE_ACCOUNT = '2-1100';

    /** Fallback term when the vendor row carries none. */
    private const DEFAULT_PAYMENT_TERM_DAYS = 30;

    public function __construct(
        private readonly JournalService $journals,
    ) {}

    /**
     * Retention held on this SPK, from BOTH subledgers, because they can
     * legitimately disagree for a while and the difference is what may not be
     * released yet:
     *
     *   retained    withheld by approved opnames — the project side's number,
     *               and what the SPK report has always shown;
     *   posted      of that, the part an APPROVED opname bill actually credited
     *               to 2-1500 — the general ledger's number;
     *   balance     retained − released, unchanged;
     *   releasable  posted − released, i.e. how much of the balance has a
     *               liability behind it that a release can debit.
     *
     * @return array{retained: float, posted: float, released: float, balance: float, releasable: float}
     */
    public function balance(Subcontract $subcontract): array
    {
        // Only approved (or later closed) opnames have actually withheld money.
        $retained = round((float) $subcontract->claims()
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->sum('retention_amount'), 2);

        $posted = $this->postedRetention($subcontract);
        $released = $this->releasedRetention($subcontract);

        return [
            'retained' => $retained,
            'posted' => $posted,
            'released' => $released,
            'balance' => round($retained - $released, 2),
            // Never negative: a legacy release booked before this ledger path
            // existed has no 2-1500 debit behind it, and reporting "minus 5jt
            // releasable" would only invite someone to release more.
            'releasable' => max(round($posted - $released, 2), 0.0),
        ];
    }

    /**
     * Release a tranche of retention: a bill the subcontractor can be paid, and
     * the journal that takes the liability off the balance sheet.
     */
    public function release(Subcontract $subcontract, array $data, User $by): RetentionRelease
    {
        return DB::transaction(function () use ($subcontract, $data, $by): RetentionRelease {
            /** @var Subcontract $subcontract */
            $subcontract = Subcontract::query()
                ->whereKey($subcontract->id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new LogicException('Nilai pelepasan retensi harus lebih besar dari nol.');
            }

            $releaseDate = Carbon::parse($data['release_date'])->toDateString();
            $overrideReason = trim((string) ($data['override_reason'] ?? ''));

            $balance = $this->balance($subcontract);

            // Nothing was ever withheld here, so "releasing" would invent a
            // payable out of thin air and drive 2-1500 into a debit balance —
            // the ledger would be claiming the SUBCONTRACTOR owes us retention.
            // Checked before the time gate: "may it go YET" presumes there is
            // something to let go, and this message is the one that names the
            // actual problem.
            if ($balance['retained'] <= 0.0) {
                throw new LogicException(
                    "SPK {$subcontract->code} belum memiliki opname disetujui yang menahan retensi; tidak ada yang dapat dilepas."
                );
            }

            $this->assertDefectLiabilityOver($subcontract, $releaseDate, $overrideReason);

            if ($amount > $balance['balance'] + 0.01) {
                throw new LogicException(
                    "Pelepasan {$amount} melebihi saldo retensi {$balance['balance']} pada SPK {$subcontract->code}."
                );
            }

            // The opname withheld it, but no approved bill has credited it to
            // 2-1500 yet, so there is no liability here to settle. Same debit
            // balance as above, arrived at from the other direction.
            if ($amount > $balance['releasable'] + 0.01) {
                throw new LogicException(
                    "Retensi yang sudah dibukukan pada 2-1500 baru {$balance['releasable']}; "
                    ."setujui dulu tagihan opname SPK {$subcontract->code} sebelum melepas {$amount}."
                );
            }

            $bill = $this->raiseRetentionBill($subcontract, $amount, $releaseDate, $by);

            return $subcontract->retentionReleases()->create([
                'ap_bill_id' => $bill->id,
                'release_date' => $releaseDate,
                'amount' => $amount,
                'notes' => $data['notes'] ?? null,
                // Only filled when the time gate below was overridden: the row
                // then names WHY the guarantee was let go early, alongside the
                // bill approvals naming WHO.
                'override_reason' => $overrideReason !== '' ? $overrideReason : null,
            ]);
        });
    }

    /**
     * The TIME gate (temuan #75), on top of the ledger guards in release() —
     * not instead of them. The balance checks answer "is this money actually
     * held"; this one answers "may it be let go YET": retention is the jaminan
     * cacat mutu for the masa pemeliharaan, and with nothing but balance
     * guards the 5 % could be released the day after the first opname —
     * measured on this codebase before the gate existed. The company then
     * spends the rest of the warranty period with zero leverage over defects.
     *
     * Compared against the RELEASE DATE, not today: that is the date the
     * journal carries, and backdating it into a closed month is already the
     * fiscal-period guard's problem, not this one's.
     *
     * An SPK with NO defect_liability_until is gated too. A missing date does
     * not mean "no warranty" — it means nobody recorded when it ends, and an
     * unrecorded period would otherwise make the gate vanish exactly on the
     * SPKs that predate it. Both refusals yield to an override WITH A REASON,
     * kept on the release row: early release against a BAST-II agreed early is
     * legitimate, silent early release is not.
     */
    private function assertDefectLiabilityOver(
        Subcontract $subcontract,
        string $releaseDate,
        string $overrideReason,
    ): void {
        if ($overrideReason !== '') {
            return;
        }

        $until = $subcontract->defect_liability_until?->toDateString();

        if ($until === null) {
            throw new LogicException(
                "SPK {$subcontract->code} belum mencatat akhir masa pemeliharaan (defect_liability_until); "
                .'lengkapi tanggalnya pada SPK, atau lepaskan dengan alasan override bila retensi memang '
                .'sudah boleh dibayar.'
            );
        }

        if ($releaseDate < $until) {
            throw new LogicException(
                "Retensi SPK {$subcontract->code} baru dapat dilepas setelah masa pemeliharaan berakhir "
                ."({$until}); pelepasan tanggal {$releaseDate} hanya dapat dilakukan dengan alasan override."
            );
        }
    }

    /**
     * The payable a released retention becomes — built and journalled here
     * rather than through ApBillService, deliberately.
     *
     * EVERY route ApBillService offers debits the value to a COST account
     * (5-xxxx per category with a project, 6-4100 without) and writes a
     * fin_project_costs row to match. That is exactly wrong for this document:
     * the cost of the work was recognised in full when the opname was billed —
     * retention is withheld FROM that bill's payable, it is not a separate
     * purchase — so the ordinary path would charge the project a second time
     * for the retained percentage, leave the RAP realisasi overstated, and
     * still never touch 2-1500. Hence the hand-built row and this journal:
     *
     *   Dr 2-1500 Hutang Retensi Subkon    amount
     *       Cr 2-1100 Hutang Usaha         amount
     *
     * one liability becoming another, no cost, no tax. The DPP carries the
     * amount with zero PPN and zero PPh on purpose: both were charged on the
     * FULL period DPP of the opname (PP 9/2022 taxes the work done, not the
     * cash paid), so charging them again on the release would double the tax on
     * the retained slice.
     *
     * The journal is filed under reference_type 'ap_bill' like any other bill,
     * so Finance's own machinery keeps working on it unchanged — in particular
     * ApBillService::cancel(), which reverses precisely these two lines when a
     * release was a mistake. balance() stops counting a release whose bill was
     * cancelled, so the SPK and the ledger agree again the moment it happens.
     */
    private function raiseRetentionBill(
        Subcontract $subcontract,
        float $amount,
        string $releaseDate,
        User $by,
    ): ApBill {
        $termDays = (int) ($subcontract->vendor?->payment_term_days ?? self::DEFAULT_PAYMENT_TERM_DAYS);

        $bill = new ApBill([
            'vendor_id' => (int) $subcontract->vendor_id,
            'project_id' => $subcontract->project_id,
            'purchase_order_id' => null,
            'goods_receipt_id' => null,
            // NOT keyed to an opname: this bill settles the accumulated
            // retention of the SPK, which several opnames may have contributed
            // to, and keying it would make ApBillService believe the opname was
            // billed twice.
            'subcontract_claim_id' => null,
            'is_advance' => false,
            'bill_date' => $releaseDate,
            'due_date' => Carbon::parse($releaseDate)->addDays($termDays)->toDateString(),
            'description' => "Pelepasan retensi {$subcontract->code} — {$subcontract->title}",
            'dpp' => $amount,
            'ppn_amount' => 0,
            'pph_tax_id' => null,
            'pph_amount' => 0,
            'retention_amount' => 0,
            'total_payable' => $amount,
            'amount_paid' => 0,
            'gl_cleared_amount' => 0,
            'advance_applied_amount' => 0,
            'vendor_invoice_no' => '', // the column is not nullable; there is no vendor faktur behind a release
        ]);
        $bill->status = DocumentStatus::Draft;
        $bill->save(); // HasDocumentNumber fills the BIL code

        // Through the normal lifecycle so core_approvals carries who released
        // the retention, exactly like any other approved bill.
        //
        // Submitted by NOBODY on purpose — and the null is what SILENCES
        // SegregationOfDuties, not what satisfies it: assertNotSubmitter finds
        // no submitter and returns early. That is only honest because the route
        // (Routes/api.php) demands fin.approve as well as scm.post, so the id
        // on the `approved` row genuinely holds the AP approval right. Until it
        // did, bare scm.post minted an approved, immediately payable bill while
        // this comment claimed the act was "gated by fin.post" — a gate that
        // never existed.
        //
        // Submitting as $by instead was considered and rejected: it would
        // record an "Ajukan" click no human made, and maker-checker would then
        // refuse $by's own approval one line later — forcing a bypass flag on
        // approve(), and a bypass flag on the trait every document shares is
        // the thing that leaks into a controller a year from now. There is
        // genuinely no maker here: the amount is derived from opnames that each
        // passed their own submit → approve, so the trail "Diajukan: Sistem /
        // Disetujui: <pelepas>" describes what actually happened.
        $bill->submit(null);
        $bill->approve($by, "Pelepasan retensi {$subcontract->code}");

        $this->journals->autoPost(
            'ap_bill',
            (int) $bill->id,
            [
                [
                    'account_code' => self::SUBCON_RETENTION_ACCOUNT,
                    'debit' => $amount,
                    'description' => "Pelepasan retensi subkon {$subcontract->code}",
                    'project_id' => $subcontract->project_id,
                ],
                [
                    'account_code' => self::TRADE_PAYABLE_ACCOUNT,
                    'credit' => $amount,
                    'description' => "Hutang usaha {$bill->code}",
                    'project_id' => $subcontract->project_id,
                ],
            ],
            $releaseDate,
            "Bill {$bill->code} — {$bill->description}",
            (int) $by->id,
        );

        return $bill->refresh();
    }

    /**
     * Retention this SPK has actually CREDITED to 2-1500, read off the approved
     * opname bills rather than off the opnames themselves.
     *
     * An opname is approved by the project side; the liability only exists once
     * Finance approves the bill built from it. Releasing in between would debit
     * a credit nobody had raised.
     *
     * Cancelled bills are excluded by the status filter and soft-deleted ones by
     * the model's own scope — in both cases the credit is gone, so the matching
     * retention is not releasable either.
     */
    private function postedRetention(Subcontract $subcontract): float
    {
        $claimIds = $subcontract->claims()->pluck('id');

        if ($claimIds->isEmpty()) {
            return 0.0;
        }

        return round((float) ApBill::query()
            ->whereIn('subcontract_claim_id', $claimIds)
            ->where('status', DocumentStatus::Approved->value)
            ->sum('retention_amount'), 2);
    }

    /**
     * Retention already released, ignoring releases whose bill was cancelled:
     * that cancellation reversed the Dr 2-1500, so the money is held again and
     * the row is history rather than a release.
     */
    private function releasedRetention(Subcontract $subcontract): float
    {
        $cancelled = ApBill::query()
            ->where('status', DocumentStatus::Cancelled->value)
            ->whereIn('id', $subcontract->retentionReleases()->whereNotNull('ap_bill_id')->pluck('ap_bill_id'))
            ->pluck('id')
            ->all();

        return round((float) $subcontract->retentionReleases()
            // `ap_bill_id NOT IN (...)` is UNKNOWN for NULL, so a pre-ledger
            // release has to be named explicitly or it would silently stop
            // counting and the same money could be released twice.
            ->when($cancelled !== [], fn ($query) => $query->where(
                fn ($where) => $where->whereNull('ap_bill_id')->orWhereNotIn('ap_bill_id', $cancelled)
            ))
            ->sum('amount'), 2);
    }
}
