<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractItem;

/**
 * Opname (progress claim) math per SPK:
 *
 *   gross          = sum(period item amounts)
 *   retention      = gross * retention_pct / 100   (withheld, released after masa pemeliharaan)
 *   net_before_tax = gross - retention
 *   recovery       = potongan uang muka (AdvanceService::recoveryFor; 0 without a funded DP)
 *   ppn            = (gross - recovery) * ppn_rate / 100  (PKP vendors only; see recalcTotals
 *                    for why the recovered slice is out of the base)
 *   pph_final      = gross * pph_rate / 100        (PPh final jasa konstruksi on the FULL DPP, PP 9/2022)
 *   net_payable    = net_before_tax + ppn - pph_final - recovery
 *
 * A DP claim (is_advance) skips all of this: its math lives in
 * AdvanceService::recalcAdvance, it has no progress lines, and it never counts
 * against the plafon — see the advance branches below.
 */
class ClaimService
{
    public function __construct(
        private readonly AdvanceService $advances,
    ) {}

    public function createClaim(array $data): ProgressClaim
    {
        return DB::transaction(function () use ($data): ProgressClaim {
            $items = Arr::pull($data, 'items', []);

            /** @var Subcontract $subcontract */
            $subcontract = Subcontract::query()
                ->whereKey($data['subcontract_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($subcontract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "SPK {$subcontract->code} is {$subcontract->status->value}; opname can only be raised against an approved SPK."
                );
            }

            $claim = new ProgressClaim([
                'subcontract_id' => $subcontract->id,
                'claim_no' => $this->nextClaimNo($subcontract),
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'notes' => $data['notes'] ?? null,
            ]);
            $claim->status = DocumentStatus::Draft;
            $claim->save(); // HasDocumentNumber fills the CLM code

            $this->syncItems($claim, $subcontract, $items);
            $this->recalcTotals($claim, $subcontract);

            return $claim->load('items.subcontractItem', 'subcontract');
        });
    }

    public function updateClaim(ProgressClaim $claim, array $data): ProgressClaim
    {
        return DB::transaction(function () use ($claim, $data): ProgressClaim {
            // Editability is decided on a RE-READ inside the transaction, not
            // on the instance route model binding fetched: an approval landing
            // between that fetch and this point leaves the instance still
            // saying draft, and the edit would overwrite an APPROVED document.
            // Since the DP branch below this is a money path — recalcAdvance
            // force-fills net_payable and payout mints the already-approved AP
            // bill from it — a stale edit was repricing a disbursement with a
            // number nobody's submit → approve ever saw.
            /** @var ProgressClaim $claim */
            $claim = ProgressClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($claim);

            $items = Arr::pull($data, 'items');

            $claim->fill(Arr::only($data, ['period_start', 'period_end', 'notes']))->save();

            $subcontract = $claim->subcontract;

            // A DP claim has no progress lines to sync and its own math; the
            // only number an edit can move is the DP itself.
            if ($claim->is_advance) {
                if (is_array($items)) {
                    throw new LogicException(
                        "Klaim uang muka {$claim->code} tidak memiliki baris progres; ubah nilainya lewat kolom amount."
                    );
                }

                if (array_key_exists('amount', $data)) {
                    $amount = round((float) $data['amount'], 2);

                    if ($amount <= 0) {
                        throw new LogicException('Nilai uang muka harus lebih besar dari nol.');
                    }

                    $this->advances->assertRecoverable($subcontract, $amount);
                    $this->advances->recalcAdvance($claim, $subcontract, $amount);
                }

                return $claim->refresh()->load('subcontract');
            }

            if (is_array($items)) {
                $this->syncItems($claim, $subcontract, $items); // lines are replaced wholesale
            }

            $this->recalcTotals($claim, $subcontract);

            return $claim->load('items.subcontractItem', 'subcontract');
        });
    }

    /**
     * Approving an opname is what moves the SPK forward: each covered line's
     * cumulative progress is bumped to the claimed value. Guards re-run
     * against LIVE data so a claim built before another opname was approved
     * (stale prev) or overshooting the SPK value can never slip through.
     */
    public function approve(ProgressClaim $claim, User $by, ?string $note = null): ProgressClaim
    {
        return DB::transaction(function () use ($claim, $by, $note): ProgressClaim {
            /** @var ProgressClaim $claim */
            $claim = ProgressClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            /** @var Subcontract $subcontract */
            $subcontract = Subcontract::query()
                ->whereKey($claim->subcontract_id)
                ->lockForUpdate()
                ->firstOrFail();

            // DP claim: no progress to bump and no plafon to consume — the DP
            // is recovered OUT of the plafon, not claimed against it. What is
            // re-checked on the live rows instead: only one approved DP per
            // SPK, and the SPK's unclaimed value still has room to recover it
            // (opnames approved while this sat submitted shrink that room).
            if ($claim->is_advance) {
                $otherAdvance = $subcontract->claims()
                    ->where('is_advance', true)
                    ->whereKeyNot($claim->id)
                    ->where('status', DocumentStatus::Approved->value)
                    ->value('code');

                if ($otherAdvance !== null) {
                    throw new LogicException(
                        "SPK {$subcontract->code} sudah memiliki klaim uang muka {$otherAdvance} yang disetujui."
                    );
                }

                $this->advances->assertRecoverable($subcontract, (float) $claim->gross_amount);

                $claim->approve($by, $note); // Approvable: submitted -> approved

                return $claim->load('subcontract');
            }

            foreach ($claim->items as $line) {
                /** @var SubcontractItem $item */
                $item = SubcontractItem::query()
                    ->whereKey($line->subcontract_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $prev = (float) $line->prev_progress_pct;
                $current = (float) $line->current_progress_pct;

                $this->assertProgressBounds($current, $prev, $item);

                // Live progress moved since this opname was drafted (another
                // opname approved in between): the claim must be recomputed.
                if (abs((float) $item->progress_pct - $prev) > 0.0001) {
                    throw new LogicException(
                        "Line \"{$item->description}\" progress is now {$item->progress_pct}% but the opname was drafted at {$prev}%; edit and resubmit the claim."
                    );
                }
            }

            $this->assertWithinContractValue($subcontract, $claim, (float) $claim->gross_amount);

            // The DP outstanding moved since this opname was drafted (another
            // opname's recovery was approved in between): approving the stale
            // deduction would recover the same rupiah twice and short-pay the
            // subcontractor. Same shape as the progress staleness check above.
            $recovery = round((float) $claim->advance_recovery_amount, 2);
            $outstanding = $this->advances->outstanding($subcontract, $claim->id);

            if ($recovery > $outstanding + 0.01) {
                throw new LogicException(
                    "Potongan uang muka {$recovery} pada {$claim->code} melebihi sisa uang muka {$outstanding}; "
                    .'ubah dan ajukan ulang opname.'
                );
            }

            $claim->approve($by, $note); // Approvable: submitted -> approved

            foreach ($claim->items as $line) {
                SubcontractItem::query()
                    ->whereKey($line->subcontract_item_id)
                    ->update(['progress_pct' => $line->current_progress_pct]);
            }

            return $claim->load('items.subcontractItem', 'subcontract');
        });
    }

    public function delete(ProgressClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
            // Bentuk yang sama dengan updateClaim: keputusan editability pada
            // baris yang dibaca ulang, bukan pada instance route binding —
            // approve yang commit di antara keduanya membuat hapus ini
            // melenyapkan dokumen approved beserta jejak angka DP-nya.
            /** @var ProgressClaim $claim */
            $claim = ProgressClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($claim);

            $claim->delete();
        });
    }

    private function syncItems(ProgressClaim $claim, Subcontract $subcontract, array $items): void
    {
        $claim->items()->delete();

        foreach ($items as $input) {
            /** @var SubcontractItem $item */
            $item = $subcontract->items()
                ->whereKey($input['subcontract_item_id'])
                ->first();

            if ($item === null) {
                throw new LogicException(
                    "Line {$input['subcontract_item_id']} does not belong to SPK {$subcontract->code}."
                );
            }

            $prev = round((float) $item->progress_pct, 4);
            $current = round((float) $input['current_progress_pct'], 4);

            $this->assertProgressBounds($current, $prev, $item);

            $period = round($current - $prev, 4);

            $claim->items()->create([
                'subcontract_item_id' => $item->id,
                'prev_progress_pct' => $prev,
                'current_progress_pct' => $current,
                'period_progress_pct' => $period,
                'amount' => round($period / 100 * (float) $item->amount, 2),
            ]);
        }
    }

    private function recalcTotals(ProgressClaim $claim, Subcontract $subcontract): ProgressClaim
    {
        $gross = round((float) $claim->items()->sum('amount'), 2);

        $this->assertWithinContractValue($subcontract, $claim, $gross);

        $retention = round($gross * (float) $subcontract->retention_pct / 100, 2);
        $netBeforeTax = round($gross - $retention, 2);
        // PPh final is computed on the FULL period DPP — retention defers
        // payment and the DP was paid early, but the WORK done this period is
        // the whole gross, and PP 9/2022 taxes the work. The DP claim itself
        // withholds nothing, so across the SPK the base sums to the value once.
        $pph = round($gross * (float) $subcontract->pph_rate / 100, 2);
        // Potongan uang muka: the slice of the DP this opname pays back.
        $recovery = $this->advances->recoveryFor($subcontract, $claim, $gross, $retention, $pph);
        // PPN mirrors the PO-advance netting: the DP claim already charged PPN
        // on the DP, so the recovered slice's PPN would be charged twice if the
        // base here stayed the full gross. (gross − recovery) keeps the SPK's
        // total PPN at value × rate across DP + opnames, exactly like a PO's
        // uang muka + final bill. Retention still does not reduce the base.
        $ppn = round(($gross - $recovery) * (float) $subcontract->ppn_rate / 100, 2);

        $claim->forceFill([
            'gross_amount' => $gross,
            'retention_amount' => $retention,
            'net_before_tax' => $netBeforeTax,
            'ppn_amount' => $ppn,
            'pph_amount' => $pph,
            'advance_recovery_amount' => $recovery,
            'net_payable' => round($netBeforeTax + $ppn - $pph - $recovery, 2),
        ])->save();

        return $claim;
    }

    /**
     * Cumulative claimed work (approved opnames + this one) can never exceed
     * the SPK value. 1-cent tolerance absorbs per-line rounding.
     *
     * The SPK value here is already addendum-adjusted — AddendumService moves
     * scm_subcontracts.value on approval — so the plafon and the addendum
     * trail can never disagree.
     *
     * A DP claim (is_advance) is EXCLUDED from the cumulative sum: it is a
     * prepayment of the same value the opnames claim, recovered out of them —
     * counting it would eat plafon twice and stop the work from ever reaching
     * 100 % (a 20 % DP would cap the opnames at 80 %).
     */
    private function assertWithinContractValue(Subcontract $subcontract, ProgressClaim $claim, float $gross): void
    {
        $approvedGross = (float) $subcontract->claims()
            ->whereKeyNot($claim->id)
            ->where('is_advance', false)
            ->where('status', DocumentStatus::Approved->value)
            ->sum('gross_amount');

        if ($approvedGross + $gross > (float) $subcontract->value + 0.01) {
            $remaining = round((float) $subcontract->value - $approvedGross, 2);

            throw new LogicException(
                "Opname of {$gross} exceeds the remaining SPK value {$remaining} on {$subcontract->code}."
            );
        }
    }

    private function assertProgressBounds(float $current, float $prev, SubcontractItem $item): void
    {
        if ($current < $prev) {
            throw new LogicException(
                "Progress on \"{$item->description}\" cannot go backwards ({$current}% < {$prev}%)."
            );
        }

        if ($current > 100) {
            throw new LogicException(
                "Progress on \"{$item->description}\" cannot exceed 100% (got {$current}%)."
            );
        }
    }

    private function assertEditable(ProgressClaim $claim): void
    {
        if (! $claim->status->isEditable()) {
            throw new LogicException(
                "Opname {$claim->code} is {$claim->status->value} and can no longer be edited."
            );
        }
    }

    private function nextClaimNo(Subcontract $subcontract): int
    {
        return (int) $subcontract->claims()->withTrashed()->max('claim_no') + 1;
    }
}
