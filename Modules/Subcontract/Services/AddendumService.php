<?php

namespace Modules\Subcontract\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractAddendum;

/**
 * Addendum SPK — pekerjaan tambah-kurang against an approved SPK, mirroring
 * Crm's ContractChangeOrderService.
 *
 * The SPK row is untouched until an addendum is APPROVED, and then its value
 * is adjusted rather than replaced — scm_subcontracts.original_value keeps
 * what was signed. The value IS the klaim plafon
 * (ClaimService::assertWithinContractValue reads it), so an approved addendum
 * moves the opname ceiling in the same write.
 *
 * Existing SPK lines are never modified. Every approved opname is
 * `period_progress_pct × item.amount`; moving an amount under claimed progress
 * would silently restate money already approved. So added scope arrives as NEW
 * lines with progress 0, and removed scope carries no lines at all — it only
 * lowers the value, and the plafon guard is what stops the surplus lines from
 * ever being claimed past the reduced value.
 */
class AddendumService
{
    public function __construct(
        private readonly AdvanceService $advances,
    ) {}

    public function create(array $data): SubcontractAddendum
    {
        return DB::transaction(function () use ($data): SubcontractAddendum {
            $subcontract = Subcontract::query()->findOrFail($data['subcontract_id']);

            // A draft or rejected SPK should be EDITED, not amended: nothing
            // is signed yet, and an addendum against it would be a second way
            // to set the same number.
            if ($subcontract->status !== DocumentStatus::Approved) {
                throw new LogicException(
                    "SPK {$subcontract->code} berstatus {$subcontract->status->value}. "
                    .'Addendum hanya berlaku atas SPK yang sudah disetujui — '
                    .'ubah nilainya langsung selama masih draf.'
                );
            }

            $addendum = new SubcontractAddendum(Arr::only($data, [
                'subcontract_id', 'addendum_date', 'title', 'description',
                'reason', 'change_type', 'value_change',
            ]));
            $addendum->status = DocumentStatus::Draft;
            $addendum->save(); // HasDocumentNumber fills the ADS code

            $this->syncItems($addendum, $data['items'] ?? []);
            $this->assertLinesMatchChange($addendum);

            return $addendum->load('items', 'subcontract');
        });
    }

    public function update(SubcontractAddendum $addendum, array $data): SubcontractAddendum
    {
        return DB::transaction(function () use ($addendum, $data): SubcontractAddendum {
            /** @var SubcontractAddendum $addendum */
            $addendum = SubcontractAddendum::query()->whereKey($addendum->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($addendum);

            $addendum->fill(Arr::only($data, [
                'addendum_date', 'title', 'description', 'reason', 'change_type', 'value_change',
            ]))->save();

            if (array_key_exists('items', $data) && is_array($data['items'])) {
                $this->syncItems($addendum, $data['items']); // lines are replaced wholesale
            }

            $this->assertLinesMatchChange($addendum);

            return $addendum->load('items', 'subcontract');
        });
    }

    public function delete(SubcontractAddendum $addendum): void
    {
        DB::transaction(function () use ($addendum): void {
            /** @var SubcontractAddendum $addendum */
            $addendum = SubcontractAddendum::query()->whereKey($addendum->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($addendum);

            $addendum->delete();
        });
    }

    /**
     * Approve, move the SPK value, append the new lines.
     *
     * The guard that matters is the floor: a reduction may not take the SPK
     * below what approved opnames have already claimed against it. Allowing
     * that would leave an SPK worth less than the sum of its own approved
     * opnames — a plafon already burst the moment it was set.
     *
     * The second floor is the uang muka: the un-recovered DP is paid back out
     * of opnames that have not happened yet, so the value left to claim
     * (newValue − claimed) must still cover it. A reduction below that line
     * would strand the remainder of the DP in 1-1500 with no opname left to
     * recover it from.
     */
    public function approve(SubcontractAddendum $addendum, User $by, ?string $note = null): SubcontractAddendum
    {
        return DB::transaction(function () use ($addendum, $by, $note): SubcontractAddendum {
            /** @var SubcontractAddendum $addendum */
            $addendum = SubcontractAddendum::query()->whereKey($addendum->id)->lockForUpdate()->firstOrFail();

            /** @var Subcontract $subcontract */
            $subcontract = Subcontract::query()->whereKey($addendum->subcontract_id)->lockForUpdate()->firstOrFail();

            $this->assertDirectorMayBeNeeded($addendum, $by);

            $change = round((float) $addendum->value_change, 2);
            $newValue = round((float) $subcontract->value + $change, 2);

            if ($newValue < 0) {
                throw new LogicException('Nilai SPK tidak boleh menjadi negatif.');
            }

            $claimed = $this->approvedWorkGross($subcontract);

            if ($newValue < $claimed - 0.01) {
                throw new LogicException(sprintf(
                    'Nilai SPK setelah addendum (%s) lebih kecil daripada yang sudah diopname (%s).',
                    number_format($newValue, 2, ',', '.'),
                    number_format($claimed, 2, ',', '.'),
                ));
            }

            $advanceOutstanding = $this->advances->outstanding($subcontract);

            if ($newValue - $claimed < $advanceOutstanding - 0.01) {
                throw new LogicException(sprintf(
                    'Sisa nilai SPK setelah addendum (%s) tidak lagi menampung uang muka yang belum '
                    .'diperhitungkan (%s); potongan uang muka tidak akan pernah selesai.',
                    number_format(round($newValue - $claimed, 2), 2, ',', '.'),
                    number_format($advanceOutstanding, 2, ',', '.'),
                ));
            }

            $addendum->approve($by, $note); // Approvable: submitted -> approved, maker-checker inside

            // Recorded once, on the first approved addendum, so the column
            // means "the value this SPK started at" rather than a copy every
            // later addendum overwrites.
            $original = $subcontract->original_value ?? $subcontract->value;

            $subcontract->forceFill([
                'original_value' => $original,
                'value' => $newValue,
            ])->save();

            // Added scope becomes ordinary SPK lines with progress 0, claimable
            // through the same opname flow as the signing-day lines.
            $lineNo = (int) $subcontract->items()->max('line_no');

            foreach ($addendum->items as $item) {
                $subcontract->items()->create([
                    'line_no' => ++$lineNo,
                    'boq_item_id' => null,
                    'wbs_code' => $item->wbs_code,
                    'description' => $item->description,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'progress_pct' => 0,
                ]);
            }

            return $addendum->refresh()->load('items', 'subcontract');
        });
    }

    public function reject(SubcontractAddendum $addendum, User $by, ?string $note = null): SubcontractAddendum
    {
        $addendum->reject($by, $note);

        return $addendum->refresh();
    }

    /**
     * What the SPK is worth now, and how it got there — mirror of
     * ContractChangeOrderService::summaryFor.
     */
    public function summaryFor(Subcontract $subcontract): array
    {
        $approved = SubcontractAddendum::query()
            ->where('subcontract_id', $subcontract->id)
            ->where('status', DocumentStatus::Approved->value)
            ->get();

        return [
            'subcontract' => $subcontract->code,
            'original_value' => (float) ($subcontract->original_value ?? $subcontract->value),
            'current_value' => (float) $subcontract->value,
            'net_change' => round((float) $approved->sum('value_change'), 2),
            'additions' => round((float) $approved->where('value_change', '>=', 0)->sum('value_change'), 2),
            'reductions' => round((float) $approved->where('value_change', '<', 0)->sum('value_change'), 2),
            'addendum_count' => $approved->count(),
            'claimed_to_date' => $this->approvedWorkGross($subcontract),
        ];
    }

    /**
     * Same permission the SPK's own director gate runs on (scm.approve-director),
     * checked in-service rather than through Procurement's DirectorApproval:
     * that guard resolves its wording through Core's ApprovableDocuments
     * registry, and an addendum missing from the registry would explode with a
     * wiring error instead of refusing the approver. Same order too — only a
     * SUBMITTED document is judged, so a draft still gets the canonical
     * "while status is draft" refusal from Approvable::assertStatus.
     */
    private function assertDirectorMayBeNeeded(SubcontractAddendum $addendum, User $by): void
    {
        if ($addendum->status !== DocumentStatus::Submitted) {
            return;
        }

        if (! (bool) $addendum->needs_director_approval) {
            return;
        }

        if ($by->can('scm.approve-director')) {
            return;
        }

        throw new LogicException(
            "Addendum {$addendum->code} membawa nilai SPK melewati ambang persetujuan direktur; "
            .'dokumen ini hanya dapat disetujui oleh pemegang izin scm.approve-director — '
            .'pada instalasi standar peran direktur.'
        );
    }

    private function syncItems(SubcontractAddendum $addendum, array $items): void
    {
        $addendum->items()->delete();

        foreach ($items as $item) {
            $qty = round((float) ($item['qty'] ?? 0), 3);
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

            if ($qty <= 0) {
                throw new LogicException('Setiap baris addendum memerlukan volume yang positif.');
            }

            $addendum->items()->create([
                'wbs_code' => $item['wbs_code'] ?? null,
                'description' => $item['description'],
                'qty' => $qty,
                'unit' => $item['unit'] ?? null,
                'unit_price' => $unitPrice,
                'amount' => round($qty * $unitPrice, 2),
            ]);
        }
    }

    /**
     * The lines and the number must tell the same story, checked while the
     * document is still editable rather than at approval:
     *
     *   positive change WITHOUT lines is a trap — the plafon rises into space
     *       no opname can ever reach, because claims are per-line progress and
     *       every existing line already sums to the old value. Eskalasi harga
     *       enters as its own line ("Eskalasi harga ...") for the same reason:
     *       that is the line the escalation is claimed on;
     *   negative change WITH lines is incoherent — removed scope removes
     *       nothing line by line (claimed progress cannot be restated); it
     *       only lowers the value;
     *   lines that do not sum to value_change would move the plafon by a
     *       different number than the claimable work it supposedly covers.
     */
    private function assertLinesMatchChange(SubcontractAddendum $addendum): void
    {
        $change = round((float) $addendum->value_change, 2);
        $linesTotal = round((float) $addendum->items()->sum('amount'), 2);
        $hasLines = $addendum->items()->exists();

        if ($change > 0 && ! $hasLines) {
            throw new LogicException(
                'Pekerjaan tambah harus membawa baris pekerjaan baru — tanpa baris, plafon naik '
                .'tetapi tidak ada baris yang dapat diopname untuk nilai tambahannya.'
            );
        }

        if ($change < 0 && $hasLines) {
            throw new LogicException(
                'Pekerjaan kurang tidak membawa baris: nilai negatif hanya menurunkan nilai SPK, '
                .'baris yang ada tidak diubah.'
            );
        }

        if ($hasLines && abs($linesTotal - $change) > 0.01) {
            throw new LogicException(sprintf(
                'Total baris addendum (%s) tidak sama dengan perubahan nilai (%s).',
                number_format($linesTotal, 2, ',', '.'),
                number_format($change, 2, ',', '.'),
            ));
        }
    }

    /**
     * Work already claimed: approved OPNAMES only. A DP claim (is_advance) is
     * not work — it is a prepayment recovered out of future opnames, and it
     * has its own floor in approve() above.
     */
    private function approvedWorkGross(Subcontract $subcontract): float
    {
        return round((float) $subcontract->claims()
            ->where('is_advance', false)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->sum('gross_amount'), 2);
    }

    private function assertEditable(SubcontractAddendum $addendum): void
    {
        if (! $addendum->status->isEditable()) {
            throw new LogicException(
                "Addendum {$addendum->code} berstatus {$addendum->status->value} dan tidak dapat diubah lagi."
            );
        }
    }
}
