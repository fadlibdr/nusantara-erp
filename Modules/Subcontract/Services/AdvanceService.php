<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Services\JournalService;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;

/**
 * Uang muka subkon (DP mobilisasi) — temuan #49, mirroring the vendor-PO
 * advance pattern in ApBillService end to end:
 *
 *   KLAIM UANG MUKA (is_advance = true)    a ProgressClaim with no progress
 *       lines: gross = the DP, retention 0 and PPh 0 (both are charged on
 *       WORK, and a DP buys no work yet), PPN on the DP for a PKP vendor. It
 *       walks the SAME draft → submit → approve flow as every opname, so the
 *       maker-checker and scm.approve gate the claims already have govern the
 *       DP too.
 *
 *   PENCAIRAN (payout)                     once the claim is approved, one
 *       explicit action mints the approved AP bill and its journal:
 *
 *           Dr 1-1500 Uang Muka Proyek     DP
 *           Dr 1-1600 PPN Masukan          PPN uang muka
 *               Cr 2-1100 Hutang Usaha     total dibayar
 *
 *       a prepaid ASSET, no project cost — which is exactly why this bill is
 *       hand-built here and not through ApBillService: every route that
 *       service offers debits the value to a COST account (5-xxxx with a
 *       project) and writes fin_project_costs to match, so the subcon cost
 *       would be booked TWICE by the time the opnames are billed. Same
 *       reasoning, same mechanics as RetentionService::raiseRetentionBill.
 *
 *   PEMOTONGAN (recovery)                  later ordinary opnames carry
 *       advance_recovery_amount — the proportional slice of the DP that
 *       opname pays back (ClaimService::recalcTotals asks recoveryFor()
 *       below). It reduces the opname's net_payable, and on the opname BILL
 *       it credits 1-1500 back out, the same netting a PO final bill does to
 *       its uang muka.
 *
 * THE ACCOUNT IS THE VENDOR-ADVANCE ACCOUNT ON PURPOSE. The chart has no
 * subcon-specific DP account — its 1-1500 is 'Uang Muka Proyek' and 2-1400/
 * 2-1500 are spoken for (customer advances and Hutang Retensi Subkon) — so
 * SPK advances share the prepaid asset PO advances live in, read through the
 * SAME accounting.purchase_advance_account setting ApBillService uses: an
 * installation that repoints its advance account moves both kinds at once
 * instead of leaving one behind.
 */
class AdvanceService
{
    /** Same shipped default as ApBillService::DEFAULT_PURCHASE_ADVANCE_ACCOUNT. */
    private const DEFAULT_ADVANCE_ACCOUNT = '1-1500';

    /** Fallback term when the vendor row carries none (mirrors RetentionService). */
    private const DEFAULT_PAYMENT_TERM_DAYS = 30;

    public function __construct(
        private readonly JournalService $journals,
    ) {}

    /**
     * Draft the DP claim. It enters the ordinary claim lifecycle from here —
     * submit and approve run through the progress-claims routes unchanged.
     */
    public function createClaim(Subcontract $subcontract, array $data): ProgressClaim
    {
        return DB::transaction(function () use ($subcontract, $data): ProgressClaim {
            /** @var Subcontract $subcontract */
            $subcontract = Subcontract::query()
                ->whereKey($subcontract->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($subcontract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "SPK {$subcontract->code} is {$subcontract->status->value}; uang muka hanya dapat "
                    .'diajukan atas SPK yang sudah disetujui.'
                );
            }

            // At most one live DP per SPK, mirroring "at most one advance per
            // PO". A rejected or deleted claim is not live — the DP can be
            // re-drafted after it.
            $existing = $subcontract->claims()
                ->where('is_advance', true)
                ->whereIn('status', [
                    DocumentStatus::Draft->value,
                    DocumentStatus::Submitted->value,
                    DocumentStatus::Approved->value,
                ])
                ->value('code');

            if ($existing !== null) {
                throw new LogicException(
                    "SPK {$subcontract->code} sudah memiliki klaim uang muka {$existing}."
                );
            }

            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new LogicException('Nilai uang muka harus lebih besar dari nol.');
            }

            $this->assertRecoverable($subcontract, $amount);

            $date = Carbon::parse($data['claim_date'] ?? now())->toDateString();

            $claim = new ProgressClaim([
                'subcontract_id' => $subcontract->id,
                // Same rule as ClaimService::nextClaimNo — the DP takes its
                // place in the SPK's claim sequence like any other claim.
                'claim_no' => (int) $subcontract->claims()->withTrashed()->max('claim_no') + 1,
                'is_advance' => true,
                // A DP has no work period; both bounds carry the claim date so
                // the NOT NULL columns stay honest instead of inventing one.
                'period_start' => $date,
                'period_end' => $date,
                'notes' => $data['notes'] ?? null,
            ]);
            $claim->status = DocumentStatus::Draft;
            $claim->save(); // HasDocumentNumber fills the CLM code

            return $this->recalcAdvance($claim, $subcontract, $amount);
        });
    }

    /**
     * DP claim math, shared by create and by ClaimService::updateClaim's
     * advance branch. No retention, no PPh — see the class docblock — and PPN
     * follows the SPK's own rate, already 0 for a non-PKP vendor.
     */
    public function recalcAdvance(ProgressClaim $claim, Subcontract $subcontract, float $amount): ProgressClaim
    {
        $ppn = round($amount * (float) $subcontract->ppn_rate / 100, 2);

        $claim->forceFill([
            'gross_amount' => $amount,
            'retention_amount' => 0,
            'net_before_tax' => $amount,
            'ppn_amount' => $ppn,
            'pph_amount' => 0,
            'advance_recovery_amount' => 0,
            'net_payable' => round($amount + $ppn, 2),
        ])->save();

        return $claim->load('subcontract');
    }

    /**
     * Pay the approved DP claim out: the approved AP bill and the prepaid-
     * asset journal, in one in-lane action.
     *
     * Route-gated on scm.post AND fin.approve for the reason the retention
     * release is (see Routes/api.php): the bill is minted already approved —
     * submitted by nobody, approved by the releaser — so the id landing on its
     * `approved` row must genuinely hold the AP approval right.
     */
    public function payout(Subcontract $subcontract, array $data, User $by): ApBill
    {
        return DB::transaction(function () use ($subcontract, $data, $by): ApBill {
            /** @var Subcontract $subcontract */
            $subcontract = Subcontract::query()
                ->whereKey($subcontract->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ?ProgressClaim $claim */
            $claim = $subcontract->claims()
                ->where('is_advance', true)
                ->where('status', DocumentStatus::Approved->value)
                ->first();

            if ($claim === null) {
                throw new LogicException(
                    "SPK {$subcontract->code} belum memiliki klaim uang muka yang disetujui; tidak ada yang dapat dicairkan."
                );
            }

            $existing = ApBill::query()
                ->where('subcontract_claim_id', $claim->id)
                ->whereNot('status', DocumentStatus::Cancelled->value)
                ->value('code');

            if ($existing !== null) {
                throw new LogicException(
                    "Uang muka {$claim->code} sudah dicairkan lewat tagihan {$existing}."
                );
            }

            // Re-asserted on LIVE numbers: opnames approved since the claim
            // was drafted shrink the room the DP recovers out of.
            $this->assertRecoverable($subcontract, (float) $claim->gross_amount);

            $payoutDate = Carbon::parse($data['payout_date'])->toDateString();

            return $this->raiseAdvanceBill($subcontract, $claim, $payoutDate, $by);
        });
    }

    /**
     * The DP this SPK can still recover out of future opnames — the number
     * ClaimService nets off, and the SPK screen's "Uang muka belum
     * diperhitungkan".
     *
     * Only a FUNDED advance counts, for the reason ApBillService only counts
     * APPROVED advance bills: a DP whose payout bill was never raised (or was
     * cancelled) never debited 1-1500, so there is nothing to credit back —
     * an opname deducting it would short-pay the subcontractor for money he
     * never received.
     *
     * $excludeClaimId leaves one claim's own recovery out of the recovered
     * sum, so a claim being recomputed does not double-count itself.
     */
    public function outstanding(Subcontract $subcontract, ?int $excludeClaimId = null): float
    {
        $advance = $this->fundedAdvance($subcontract);

        if ($advance === null) {
            return 0.0;
        }

        $recovered = round((float) $subcontract->claims()
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->when($excludeClaimId !== null, fn ($query) => $query->whereKeyNot($excludeClaimId))
            ->sum('advance_recovery_amount'), 2);

        return max(round((float) $advance->gross_amount - $recovered, 2), 0.0);
    }

    /**
     * The recovery an opname of $gross should carry, asked by
     * ClaimService::recalcTotals.
     *
     * Proportional — DP ÷ nilai SPK per rupiah of work — so a 20 % DP is paid
     * back 20 sen per rupiah opnamed and finishes exactly when the SPK is
     * fully claimed. Two corrections on top of the proportion:
     *
     *   catch-up    the opname that brings cumulative work to the full SPK
     *               value takes the whole remainder, so per-claim rounding (or
     *               an opname approved before the DP was paid out, which
     *               deducts nothing) cannot strand the last few rupiah;
     *   floor       never more than gross − retention − PPh, so the opname's
     *               net_payable cannot go negative. What the floor leaves
     *               unrecovered stays in `outstanding` and rolls to the next
     *               opname — visible on the SPK screen until it clears.
     */
    public function recoveryFor(
        Subcontract $subcontract,
        ProgressClaim $claim,
        float $gross,
        float $retention,
        float $pph,
    ): float {
        $advance = $this->fundedAdvance($subcontract);

        if ($advance === null) {
            return 0.0;
        }

        $outstanding = $this->outstanding($subcontract, $claim->id);
        $value = (float) $subcontract->value;

        if ($outstanding <= 0.0 || $value <= 0.0) {
            return 0.0;
        }

        $recovery = round($gross * (float) $advance->gross_amount / $value, 2);

        $othersGross = round((float) $subcontract->claims()
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->whereKeyNot($claim->id)
            ->sum('gross_amount'), 2);

        if ($othersGross + $gross >= $value - 0.01) {
            $recovery = $outstanding; // catch-up: this opname closes the SPK out
        }

        $recovery = min($recovery, $outstanding, round($gross - $retention - $pph, 2));

        return max($recovery, 0.0);
    }

    /**
     * Everything the SPK screen needs to tell the DP's story.
     */
    public function panelFor(Subcontract $subcontract): array
    {
        /** @var ?ProgressClaim $claim */
        $claim = $subcontract->claims()
            ->where('is_advance', true)
            ->whereIn('status', [
                DocumentStatus::Draft->value,
                DocumentStatus::Submitted->value,
                DocumentStatus::Approved->value,
            ])
            ->first();

        $bill = $claim === null ? null : ApBill::query()
            ->where('subcontract_claim_id', $claim->id)
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->first();

        $recovered = round((float) $subcontract->claims()
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->sum('advance_recovery_amount'), 2);

        return [
            'claim_id' => $claim?->id,
            'claim_code' => $claim?->code,
            'claim_status' => $claim?->status?->value,
            'amount' => $claim === null ? 0.0 : round((float) $claim->gross_amount, 2),
            'bill_id' => $bill?->id,
            'bill_code' => $bill?->code,
            'paid_out' => $bill !== null && $bill->status === DocumentStatus::Approved,
            'recovered' => $recovered,
            'outstanding' => $this->outstanding($subcontract),
        ];
    }

    /**
     * The approved DP claim whose payout bill is APPROVED and alive — the only
     * advance an opname may deduct. See outstanding() for why.
     */
    private function fundedAdvance(Subcontract $subcontract): ?ProgressClaim
    {
        /** @var ?ProgressClaim $claim */
        $claim = $subcontract->claims()
            ->where('is_advance', true)
            ->where('status', DocumentStatus::Approved->value)
            ->first();

        if ($claim === null) {
            return null;
        }

        $funded = ApBill::query()
            ->where('subcontract_claim_id', $claim->id)
            ->where('status', DocumentStatus::Approved->value)
            ->exists();

        return $funded ? $claim : null;
    }

    /**
     * The DP is recovered out of opnames that have not happened yet, so it may
     * never exceed the SPK value still unclaimed. Asserted when the claim is
     * drafted, when it is approved AND when it is paid out — each on live
     * numbers, because opnames keep landing in between.
     */
    public function assertRecoverable(Subcontract $subcontract, float $amount): void
    {
        $claimed = round((float) $subcontract->claims()
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->sum('gross_amount'), 2);

        $remaining = round((float) $subcontract->value - $claimed, 2);

        if ($amount > $remaining + 0.01) {
            throw new LogicException(
                "Uang muka {$amount} melebihi sisa nilai SPK yang belum diopname ({$remaining}) "
                ."pada {$subcontract->code}; tidak akan ada opname yang cukup untuk memotongnya kembali."
            );
        }
    }

    /**
     * The payable behind the DP — built and journalled here rather than
     * through ApBillService, for the reason laid out on the class docblock:
     * the DP is an ASSET, and every route that service offers would expense it
     * to project cost and double the subcon cost when the opnames arrive.
     *
     * Keyed to the DP claim (subcontract_claim_id) ON PURPOSE: that is what
     * makes ApBillService::createFromSubconClaim's "a bill already exists for
     * this opname" guard refuse the same claim being billed a second time down
     * the cost path. is_advance = true describes what the bill is, and keeps
     * ApBillService::cancel treating it as the prepayment it is.
     *
     * Submitted by NOBODY, approved by the releaser — the retention-release
     * precedent, with the same honesty argument: there is no maker here,
     * because the DP amount already passed the claim's own submit → approve
     * pair; the route demands fin.approve so the `approved` row genuinely
     * carries the AP approval right.
     */
    private function raiseAdvanceBill(
        Subcontract $subcontract,
        ProgressClaim $claim,
        string $payoutDate,
        User $by,
    ): ApBill {
        $termDays = (int) ($subcontract->vendor?->payment_term_days ?? self::DEFAULT_PAYMENT_TERM_DAYS);
        $dpp = round((float) $claim->gross_amount, 2);
        $ppn = round((float) $claim->ppn_amount, 2);

        $bill = new ApBill([
            'vendor_id' => (int) $subcontract->vendor_id,
            'project_id' => $subcontract->project_id,
            'purchase_order_id' => null,
            'goods_receipt_id' => null,
            'subcontract_claim_id' => (int) $claim->id,
            'is_advance' => true,
            'bill_date' => $payoutDate,
            'due_date' => Carbon::parse($payoutDate)->addDays($termDays)->toDateString(),
            'description' => "Uang muka {$subcontract->code} — {$subcontract->title}",
            'dpp' => $dpp,
            'ppn_amount' => $ppn,
            'pph_tax_id' => null,
            'pph_amount' => 0,
            'retention_amount' => 0,
            'total_payable' => round($dpp + $ppn, 2),
            'amount_paid' => 0,
            'gl_cleared_amount' => 0,
            'advance_applied_amount' => 0,
            'vendor_invoice_no' => '', // the column is not nullable; there is no vendor faktur behind a DP payout
        ]);
        $bill->status = DocumentStatus::Draft;
        $bill->save(); // HasDocumentNumber fills the BIL code

        $bill->submit(null);
        $bill->approve($by, "Pencairan uang muka {$subcontract->code}");

        $lines = [
            [
                'account_code' => $this->advanceAccountCode(),
                'debit' => $dpp,
                'description' => "Uang muka subkon {$subcontract->code}",
                'project_id' => $subcontract->project_id,
            ],
            [
                'account_code' => '1-1600',
                'debit' => $ppn,
                'description' => "PPN Masukan {$bill->code}",
                'project_id' => $subcontract->project_id,
            ],
            [
                'account_code' => '2-1100',
                'credit' => round($dpp + $ppn, 2),
                'description' => "Hutang usaha {$bill->code}",
                'project_id' => $subcontract->project_id,
            ],
        ];

        $this->journals->autoPost(
            'ap_bill',
            (int) $bill->id,
            // autoPost drops the Rp 0 PPN leg of a non-PKP vendor before it
            // resolves the account, same as every other bill journal.
            $lines,
            $payoutDate,
            "Bill {$bill->code} — {$bill->description}",
            (int) $by->id,
        );

        return $bill->refresh();
    }

    private function advanceAccountCode(): string
    {
        return Erp::string('accounting.purchase_advance_account', self::DEFAULT_ADVANCE_ACCOUNT);
    }
}
