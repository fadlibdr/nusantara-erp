<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\ExternalApproval;
use Modules\Core\Models\Location;
use Modules\Crm\Models\Contract;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Projects\Models\ContractVariation;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;

/**
 * OPNAME KE PEMILIK (OPN) — volume measured per BOQ item, per contract, per
 * period. The revenue-side twin of Subcontract\Services\ClaimService, and its
 * shape is deliberately the same one: quantities roll forward from what is
 * already APPROVED, every guard re-runs on LIVE data at approval, and the
 * document walks the ordinary submit → approve cycle.
 *
 * THE CEILING IS THE POINT OF THIS SERVICE.
 *
 *     qty_cum  ≤  BOQ qty  +  Σ qty_change of APPROVED change orders
 *
 * Both halves matter. Reading only the BOQ would refuse every legitimate
 * addendum volume; reading unapproved change orders would let a claim ride a
 * variation nobody has signed. The refusal names the item and both numbers
 * because those two facts decide which of the two possible repairs is needed —
 * fix the opname, or record the CCO's volume (prj_contract_variations, whose
 * migration explains why that table has to exist at all).
 *
 * WHAT "ONE ITEM" MEANS HERE — AND WHY IT IS NOT THE ROW ID. A BOQ gets revised:
 * BoqService::copyVersion clones it at version + 1 and every line of the copy is
 * a NEW est_boq_items row. Keying the history on that row id made the whole
 * approved history unreachable the moment the revision was approved — qty_prev
 * fell back to 0,000, the ceiling reset to the fresh line's qty, and the same
 * cubic metre could be measured, approved and billed a second time against a
 * contract that bought it once. So every number below is keyed on the item's
 * NUMBER (est_boq_items.wbs_code), which is what actually survives a revision:
 * copyVersion carries it, and so does the "buat Versi Baru lalu impor" route
 * BoqService::dependencyBlockers sends an estimator down — that path rebuilds
 * the lines from a spreadsheet, so any lineage id planted in the copy would be
 * wiped by the very workflow the system recommends. The item number is not.
 *
 * Two consequences worth saying out loud rather than discovering:
 *   - Duplicate item numbers inside one BOQ (BoqService::duplicateWbsCodes warns
 *     about them, the database allows them) collapse into ONE identity here:
 *     their measured volumes are summed and so are their contract quantities.
 *     An earlier version of this comment said that could only make the ceiling
 *     TIGHTER. IT IS NOT TRUE, and the sentence has to go rather than be
 *     softened: two rows numbered A.1 at 1.000 and 300 give EITHER row a
 *     ceiling of 1.300 where row-id keying gave the first one 1.000 — looser
 *     for that row, by 300.
 *
 *     What IS true is the thing the sentence was reaching for: the collapse
 *     cannot bill the same volume twice, because both sides move together. The
 *     summed quantity is measured against the summed history, so 1.300 is the
 *     ceiling for the PAIR and every approved line numbered A.1 is already
 *     counted against it — which is the correct reading of a sheet that says
 *     A.1 twice, and a strictly better one than pretending the two rows name
 *     two different pieces of work. A comment that claims a safety it does not
 *     provide is worse than no comment, because it stops the next reader
 *     checking; ProgressMeasurementBoqRevisionTest pins both halves.
 *   - The ceiling reads the CURRENT approved BOQ's quantity PLUS the approved
 *     CCO register. If a revision was drawn to ABSORB an addendum's volume
 *     instead of standing beside it, its qty_change is still in the register and
 *     still counts; superseding a signed CCO is a decision for the register, not
 *     something this service may infer from a version number.
 *
 * WHAT AN APPROVED OPNAME THEN FEEDS. It becomes the project's actual
 * percentage — value-weighted over the BOQ, `actualPctAt()` below — replacing
 * the hand-typed percentage in prj_weekly_progress for every week it covers.
 * That is the whole reason this document is worth building: a percentage
 * somebody typed is an opinion, and the same percentage derived from measured
 * volume at contract prices is a measurement. Weeks no opname covers keep the
 * manual number and SAY SO (prj_weekly_progress.actual_pct_source), because a
 * curve that cannot tell you which of the two it is showing is worse than
 * either.
 */
class MeasurementService
{
    /** Quantities are decimal(15,3); half a milli-unit absorbs the rounding. */
    private const QTY_TOLERANCE = 0.0005;

    public function create(array $data): ProgressMeasurement
    {
        return DB::transaction(function () use ($data): ProgressMeasurement {
            $items = Arr::pull($data, 'items', []);

            $project = Project::query()->findOrFail((int) $data['project_id']);
            $project->assertOperational('opname progres');

            $contract = $this->contractOf($project);
            $this->assertPeriod($data['period_start'] ?? null, $data['period_end'] ?? null);

            $measurement = new ProgressMeasurement([
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'measurement_no' => $this->nextMeasurementNo($contract),
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'notes' => $data['notes'] ?? null,
            ]);
            $measurement->status = DocumentStatus::Draft;
            $measurement->save(); // HasDocumentNumber fills the OPN code

            $this->syncItems($measurement, $contract, $items);
            $this->recalcTotals($measurement);

            return $measurement->load('items', 'project', 'contract');
        });
    }

    public function update(ProgressMeasurement $measurement, array $data): ProgressMeasurement
    {
        return DB::transaction(function () use ($measurement, $data): ProgressMeasurement {
            // Editability decided on a re-read INSIDE the transaction, the
            // ClaimService::updateClaim rule: an approval landing between the
            // route binding and this line leaves the instance still saying
            // draft, and this edit would rewrite an approved measurement — the
            // document an owner invoice's DPP is computed from.
            /** @var ProgressMeasurement $measurement */
            $measurement = ProgressMeasurement::query()
                ->whereKey($measurement->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($measurement);

            $items = Arr::pull($data, 'items');

            $this->assertPeriod(
                array_key_exists('period_start', $data) ? $data['period_start'] : $measurement->period_start,
                array_key_exists('period_end', $data) ? $data['period_end'] : $measurement->period_end,
            );

            $measurement->fill(Arr::only($data, ['period_start', 'period_end', 'notes']))->save();

            if (is_array($items)) {
                $this->syncItems($measurement, $this->contractRow($measurement), $items); // replaced wholesale
            }

            $this->recalcTotals($measurement);

            return $measurement->load('items', 'project', 'contract');
        });
    }

    public function delete(ProgressMeasurement $measurement): void
    {
        DB::transaction(function () use ($measurement): void {
            /** @var ProgressMeasurement $measurement */
            $measurement = ProgressMeasurement::query()
                ->whereKey($measurement->id)->lockForUpdate()->firstOrFail();

            $this->assertEditable($measurement);
            $this->assertNotBilled($measurement);

            $measurement->items()->delete();
            $measurement->delete();
        });
    }

    /**
     * Approving is what makes the measured volume count — against the ceiling
     * for the next opname, and as the project's actual percentage. Every guard
     * therefore re-runs against LIVE rows: an opname drafted before another one
     * was approved carries a stale qty_prev, and approving it would claim the
     * same cubic metre twice.
     */
    public function approve(ProgressMeasurement $measurement, User $by, ?string $note = null): ProgressMeasurement
    {
        return DB::transaction(function () use ($measurement, $by, $note): ProgressMeasurement {
            /** @var ProgressMeasurement $measurement */
            $measurement = ProgressMeasurement::query()
                ->whereKey($measurement->id)->lockForUpdate()->firstOrFail();

            $contract = $this->contractRow($measurement);
            $project = $measurement->project()->first();
            $index = 0;

            foreach ($measurement->items as $line) {
                $item = BoqItem::query()->find($line->boq_item_id);

                if ($item === null) {
                    throw ValidationException::withMessages(["items.{$index}.boq_item_id" => sprintf(
                        'Item BOQ pada baris %d sudah tidak ada; ubah dan ajukan ulang opname %s.',
                        $index + 1,
                        $measurement->code,
                    )]);
                }

                $livePrev = $this->approvedQtyFor($contract, $this->identityOf($item), (int) $measurement->id);

                if (abs($livePrev - (float) $line->qty_prev) > self::QTY_TOLERANCE) {
                    throw ValidationException::withMessages(["items.{$index}.qty_prev" => sprintf(
                        'Volume kumulatif item "%s" kini %s %s sedangkan opname ini disusun atas %s %s; '
                        .'ubah dan ajukan ulang opname.',
                        $item->description,
                        $this->qty($livePrev),
                        $item->unit,
                        $this->qty((float) $line->qty_prev),
                        $item->unit,
                    )]);
                }

                $this->assertWithinCeiling(
                    $contract,
                    $project,
                    $item,
                    round($livePrev + (float) $line->qty_this, 3),
                    $index,
                );

                $index++;
            }

            $measurement->approve($by, $note); // Approvable: submitted -> approved

            $this->refreshWeeklyFromMeasurements($measurement);

            return $measurement->load('items', 'project', 'contract');
        });
    }

    public function reject(ProgressMeasurement $measurement, User $by, ?string $note = null): ProgressMeasurement
    {
        return DB::transaction(fn (): ProgressMeasurement => $measurement->reject($by, $note));
    }

    /**
     * ADAPTER TRANSISI — the MK's decision on this opname, arriving through a
     * one-time link or a recorded paper sheet, moves the document.
     *
     * Same contract and same reasoning as WorkPermitService::applyExternalDecision:
     * the transition runs on behalf of the link's ISSUER (owner decision #6
     * keeps the internal proxy, and an MK is not a users row), inside the
     * decision's own transaction, so a refusal here rolls the recording back
     * rather than leaving a decision that was recorded but not applied. Who
     * really decided lives in core_external_approvals; core_approvals carries
     * the proxy plus a note naming them.
     *
     * Setuju and setuju-dengan-catatan both approve — through THIS service, not
     * the trait, so the ceiling is re-checked against live rows and the weekly
     * curve is refreshed exactly as an internal approval does.
     */
    public function applyExternalDecision(ProgressMeasurement $measurement, ExternalApproval $approval): ProgressMeasurement
    {
        $issuer = $approval->issued_by === null ? null : User::query()->find($approval->issued_by);

        if ($issuer === null) {
            throw new LogicException(sprintf(
                'Keputusan eksternal atas opname %s tidak dapat diterapkan: penerbit tautannya tidak dikenal, '
                .'padahal transisi dijalankan atas nama penerbit.',
                $measurement->code,
            ));
        }

        $note = sprintf(
            'Keputusan eksternal %s: %s — %s%s, via %s.%s',
            $approval->partyLabel(),
            $approval->decision?->label(),
            $approval->name,
            filled($approval->organization) ? " ({$approval->organization})" : '',
            $approval->decided_via === ExternalApproval::VIA_PHYSICAL ? 'lembar fisik' : 'tautan',
            filled($approval->decision_notes) ? " Catatan: {$approval->decision_notes}" : '',
        );

        return $approval->decision?->isApproval() === true
            ? $this->approve($measurement, $issuer, $note)
            : $this->reject($measurement, $issuer, $note);
    }

    // ------------------------------------------------------------- the ceiling

    /**
     * The CURRENT approved BOQ's qty for this item + Σ qty_change of APPROVED
     * change orders touching it, on one contract.
     *
     * Both halves are keyed on the item's NUMBER, not on the row id it happens
     * to have today — see the class docblock. Reading the current BOQ rather
     * than the qty of the row handed in is what makes a revision that RAISES a
     * volume actually raise the ceiling, and a revision that lowers one lower
     * it, whichever version's row the caller is holding.
     *
     * The change-order status is read BY VALUE off crm_contract_change_orders
     * rather than through the Crm service, for the same reason
     * BastPrerequisiteService reads qc_ncr by value: this is one fact, and
     * pulling a service in to fetch it would buy a dependency for nothing.
     */
    public function ceilingFor(Contract $contract, BoqItem $item, ?Project $project = null): float
    {
        $code = $this->identityOf($item);
        $current = $this->currentBoqQty($contract, $project, [$code]);

        return round(
            ($current === null ? (float) $item->qty : ($current[$code] ?? 0.0))
            + $this->approvedVariationQty($contract, [$code])[$code],
            3,
        );
    }

    /**
     * The same ceiling for MANY items at once, keyed by boq_item_id.
     *
     * Exists for the F/OPN backsheet, which prints the plafon beside every
     * measured line: asking ceilingFor() per row would put one query per row
     * inside one print, which is the exact failure PrintableDocuments' class
     * docblock warns about. A fixed handful of queries here whatever the
     * sheet's length.
     *
     * Keyed by the id the CALLER asked about — a backsheet holds the row ids its
     * lines were measured against, which after a revision are last version's —
     * while the figure behind each key is the current one, so the printed plafon
     * is the same number the guard enforces.
     *
     * An id with no BOQ row is ABSENT from the answer rather than present as
     * 0.0 — a caller must be able to tell "the ceiling is zero" from "there is
     * no item to have a ceiling", because on a printed sheet the first is a
     * figure and the second is a ruled blank.
     *
     * @param  list<int>  $boqItemIds
     * @return array<int, float>
     */
    public function ceilingsFor(Contract $contract, array $boqItemIds, ?Project $project = null): array
    {
        $boqItemIds = array_values(array_unique(array_map('intval', $boqItemIds)));

        if ($boqItemIds === []) {
            return [];
        }

        $items = BoqItem::query()->whereIn('id', $boqItemIds)->get(['id', 'wbs_code', 'qty']);

        if ($items->isEmpty()) {
            return [];
        }

        $codes = array_values(array_unique($items->map(fn (BoqItem $item): string => $this->identityOf($item))->all()));

        $current = $this->currentBoqQty($contract, $project, $codes);
        $variations = $this->approvedVariationQty($contract, $codes);
        $ceilings = [];

        foreach ($items as $item) {
            $code = $this->identityOf($item);

            $ceilings[(int) $item->id] = round(
                ($current === null ? (float) $item->qty : ($current[$code] ?? 0.0)) + $variations[$code],
                3,
            );
        }

        return $ceilings;
    }

    /**
     * What the CURRENT BOQ of this contract says each of these item numbers is
     * worth in volume, zero-filled for a number the revision dropped.
     *
     * NULL — not an empty map — when there is no BOQ to read at all, so callers
     * can fall back to the quantity on the row they were handed instead of
     * pretending the contract bought nothing.
     *
     * @param  list<string>  $codes
     * @return array<string, float>|null
     */
    private function currentBoqQty(Contract $contract, ?Project $project, array $codes): ?array
    {
        $boq = $this->boqOf($contract, $project);

        if ($boq === null) {
            return null;
        }

        $sums = array_fill_keys($codes, 0.0);

        $rows = BoqItem::query()
            ->where('boq_id', $boq->id)
            ->whereIn('wbs_code', $codes)
            ->groupBy('wbs_code')
            ->selectRaw('wbs_code, SUM(qty) as qty_total')
            ->get();

        foreach ($rows as $row) {
            $sums[(string) $row->wbs_code] = (float) $row->qty_total;
        }

        return $sums;
    }

    /**
     * Σ qty_change of APPROVED change orders per ITEM NUMBER, zero-filled for
     * every number asked about.
     *
     * Joined through est_boq_items rather than read straight off
     * prj_contract_variations.boq_item_id: the register records the addendum
     * against the row that existed when the QS read the addendum BOQ, and a
     * later revision must not make a signed CCO's volume disappear.
     *
     * The change-order status is read BY VALUE off crm_contract_change_orders
     * rather than through the Crm service, for the same reason
     * BastPrerequisiteService reads qc_ncr by value: this is one fact, and
     * pulling a service in to fetch it would buy a dependency for nothing.
     *
     * @param  list<string>  $codes
     * @return array<string, float>
     */
    private function approvedVariationQty(Contract $contract, array $codes): array
    {
        $sums = array_fill_keys($codes, 0.0);

        $rows = ContractVariation::query()
            ->join('est_boq_items as boq_item', 'boq_item.id', '=', 'prj_contract_variations.boq_item_id')
            ->where('prj_contract_variations.contract_id', $contract->id)
            ->whereIn('boq_item.wbs_code', $codes)
            ->whereIn('prj_contract_variations.change_order_id', DB::table('crm_contract_change_orders')
                ->where('contract_id', $contract->id)
                ->where('status', DocumentStatus::Approved->value)
                ->whereNull('deleted_at')
                ->select('id'))
            ->groupBy('boq_item.wbs_code')
            ->selectRaw('boq_item.wbs_code as wbs_code, SUM(prj_contract_variations.qty_change) as qty_total')
            ->get();

        foreach ($rows as $row) {
            $sums[(string) $row->wbs_code] = (float) $row->qty_total;
        }

        return $sums;
    }

    /**
     * The identity of a BOQ line: its number, which survives a revision where
     * the row id does not.
     *
     * A blank number (the column is required, so this is a malformed import
     * rather than a workflow) groups every blank-numbered line of the contract
     * together. That direction is safe — it can only add measured volume to the
     * history and refuse earlier, never bill the same volume twice.
     */
    private function identityOf(BoqItem $item): string
    {
        return trim((string) $item->wbs_code);
    }

    /**
     * Value-weighted physical percentage as at a date: measured volume at
     * contract prices, over the contract BOQ's own total.
     *
     * Value-weighted and not volume-weighted, because 1 m3 of galian and 1 m3
     * of beton K-350 are not the same amount of project. Returns NULL — never
     * 0.0 — when there is nothing to measure with (no contract, no BOQ, no
     * approved opname yet): a zero would be indistinguishable from "measured,
     * and nothing has been built", and every caller here has to tell those two
     * apart to label its source honestly.
     */
    public function actualPctAt(Project $project, string $asOf): ?float
    {
        $contract = $project->contract_id === null
            ? null
            : Contract::query()->find($project->contract_id);

        if ($contract === null) {
            return null;
        }

        $boq = $this->boqOf($contract, $project);

        if ($boq === null) {
            return null;
        }

        $boqTotal = round((float) BoqItem::query()->where('boq_id', $boq->id)->sum('amount'), 2);

        if ($boqTotal <= 0.0) {
            return null;
        }

        $measured = ProgressMeasurement::query()
            ->where('contract_id', $contract->id)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->whereDate('period_end', '<=', $asOf)
            ->get(['id', 'period_amount']);

        if ($measured->isEmpty()) {
            return null;
        }

        $value = round((float) $measured->sum(fn ($row): float => (float) $row->period_amount), 2);

        return round($value / $boqTotal * 100, 4);
    }

    /** True when at least one approved opname covers this project by $asOf. */
    public function hasApprovedMeasurementAt(Project $project, string $asOf): bool
    {
        return $project->contract_id !== null
            && ProgressMeasurement::query()
                ->where('contract_id', $project->contract_id)
                ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
                ->whereDate('period_end', '<=', $asOf)
                ->exists();
    }

    // ------------------------------------------------------------------ rules

    private function syncItems(ProgressMeasurement $measurement, Contract $contract, array $items): void
    {
        $measurement->items()->delete();

        $project = $measurement->project()->first();
        $boq = $this->boqOf($contract, $project);

        if ($boq === null) {
            throw ValidationException::withMessages(['items' => sprintf(
                'Kontrak %s belum memiliki BOQ; opname mengukur volume per item BOQ dan tidak dapat disusun tanpanya.',
                $contract->code,
            )]);
        }

        $seen = [];

        foreach (array_values($items) as $index => $input) {
            $item = BoqItem::query()->find($input['boq_item_id'] ?? 0);

            if ($item === null || (int) $item->boq_id !== (int) $boq->id) {
                throw ValidationException::withMessages(["items.{$index}.boq_item_id" => sprintf(
                    'Item BOQ pada baris %d bukan bagian dari BOQ kontrak %s.',
                    $index + 1,
                    $contract->code,
                )]);
            }

            // Deduplicated by ITEM NUMBER, not by row id: two rows sharing a
            // number share one ceiling and one history, so two lines carrying
            // them would each measure against the whole of it.
            $identity = $this->identityOf($item);

            if (isset($seen[$identity])) {
                throw ValidationException::withMessages(["items.{$index}.boq_item_id" => sprintf(
                    'Item "%s" tercantum dua kali pada opname ini; gabungkan volumenya dalam satu baris.',
                    $item->description,
                )]);
            }
            $seen[$identity] = true;

            $prev = $this->approvedQtyFor($contract, $identity, (int) $measurement->id);
            $qtyThis = round((float) $input['qty_this'], 3);
            $cum = round($prev + $qtyThis, 3);

            if ($cum < -self::QTY_TOLERANCE) {
                throw ValidationException::withMessages(["items.{$index}.qty_this" => sprintf(
                    'Volume kumulatif item "%s" menjadi %s %s; koreksi tidak boleh membuat volume terukur negatif.',
                    $item->description,
                    $this->qty($cum),
                    $item->unit,
                )]);
            }

            $this->assertWithinCeiling($contract, $project, $item, $cum, $index);

            $measurement->items()->create([
                'boq_item_id' => $item->id,
                'location_id' => $this->locationIdFor($project, $input['location_id'] ?? null, $index),
                // Snapshots: an approved opname must keep saying what was
                // measured at the price it was measured at, whatever a later
                // BOQ revision does.
                'description' => $item->description,
                'unit' => $item->unit,
                'unit_price' => round((float) $item->unit_price, 2),
                'qty_prev' => $prev,
                'qty_this' => $qtyThis,
                'qty_cum' => $cum,
                'amount' => round($qtyThis * (float) $item->unit_price, 2),
                'notes' => $input['notes'] ?? null,
            ]);
        }
    }

    /**
     * The line's location, checked to be IN THIS PROJECT.
     *
     * The FormRequests can only ask whether the id exists in core_locations —
     * the update request does not even carry a project_id to compare it to —
     * so membership is decided here, once, for both of them and for every
     * other caller. ZoneCertificateService::locationOf already enforces exactly
     * this for a BAPP; the two documents have to agree about what a zone is.
     *
     * A FOREIGN ZONE ON AN OPNAME LINE IS WORTH MONEY. Kriteria #6 refuses to
     * bill a zone whose latest BAPP says "nunggu perbaikan", and it looks that
     * mark up among THIS project's certificates — so a line pointing at another
     * project's location can never be refused by it, whatever the state of the
     * ground it names, and sits outside the gate permanently. The F/OPN
     * backsheet meanwhile prints that location's path above our signature,
     * claiming we measured a stranger's floor.
     *
     * Null stays null: a line measuring the whole item, unallocated to a zone,
     * is ordinary and is what most opname lines are.
     */
    private function locationIdFor(?Project $project, mixed $locationId, int $index): ?int
    {
        if ($locationId === null || $locationId === '') {
            return null;
        }

        $location = Location::query()->find((int) $locationId);

        if ($location === null) {
            throw ValidationException::withMessages(["items.{$index}.location_id" => sprintf(
                'Lokasi pada baris %d tidak ditemukan.',
                $index + 1,
            )]);
        }

        // A project that has been deleted out from under a stored measurement
        // leaves nothing to compare against; the line keeps the location it was
        // measured at rather than being refused on a fact nobody can check.
        if ($project !== null && (int) $location->project_id !== (int) $project->id) {
            $owner = Project::query()->withTrashed()->find((int) $location->project_id);

            throw ValidationException::withMessages(["items.{$index}.location_id" => sprintf(
                'Lokasi %s (%s) pada baris %d bukan bagian dari proyek %s melainkan proyek %s; '
                .'opname hanya boleh mengukur lokasi proyeknya sendiri.',
                $location->code,
                $location->path(),
                $index + 1,
                $project->code,
                $owner?->code ?? 'lain (id '.(int) $location->project_id.')',
            )]);
        }

        return (int) $location->id;
    }

    private function assertWithinCeiling(Contract $contract, ?Project $project, BoqItem $item, float $cum, int $index): void
    {
        $ceiling = $this->ceilingFor($contract, $item, $project);

        if ($cum > $ceiling + self::QTY_TOLERANCE) {
            throw ValidationException::withMessages(["items.{$index}.qty_this" => sprintf(
                'Volume kumulatif item "%s" %s %s melampaui volume kontrak + CCO disetujui %s %s; '
                .'perbaiki volume opname, atau catat dahulu volume CCO-nya pada register variasi kontrak.',
                $item->description,
                $this->qty($cum),
                $item->unit,
                $this->qty($ceiling),
                $item->unit,
            )]);
        }
    }

    /**
     * Cumulative qty already APPROVED for this item on this contract.
     *
     * Summed over every approved line whose BOQ row carries the same ITEM
     * NUMBER, so a measurement made against BOQ v1 still counts once v2 is the
     * approved BOQ. Joining est_boq_items is what does that; the identity is not
     * on the measurement line itself, so a BOQ line that is hard-deleted (only
     * reachable by replacing the sections of a still-editable BOQ) takes its
     * history with it — approve() already refuses an opname whose own item row
     * has gone, and BoqService::dependencyBlockers is where that replacement
     * would have to be refused outright.
     */
    private function approvedQtyFor(Contract $contract, string $identity, ?int $excludeMeasurementId = null): float
    {
        return round((float) DB::table('prj_progress_measurement_items as items')
            ->join('prj_progress_measurements as opn', 'opn.id', '=', 'items.progress_measurement_id')
            ->join('est_boq_items as boq_item', 'boq_item.id', '=', 'items.boq_item_id')
            ->where('opn.contract_id', $contract->id)
            ->whereNull('opn.deleted_at')
            ->whereIn('opn.status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->when($excludeMeasurementId !== null, fn ($query) => $query->where('opn.id', '!=', $excludeMeasurementId))
            ->where('boq_item.wbs_code', $identity)
            ->sum('items.qty_this'), 3);
    }

    private function recalcTotals(ProgressMeasurement $measurement): void
    {
        $period = round((float) $measurement->items()->sum('amount'), 2);

        $cumulative = round((float) $measurement->items()->get()
            ->sum(fn ($line): float => (float) $line->qty_cum * (float) $line->unit_price), 2);

        $measurement->forceFill([
            'period_amount' => $period,
            'cumulative_amount' => $cumulative,
        ])->save();
    }

    /**
     * Re-derive every weekly row this contract's opnames now cover.
     *
     * Called on approval so the kurva-S stops disagreeing with the signed sheet
     * the moment the sheet is signed. Rows no opname covers are left exactly as
     * the supervisor typed them, source and all.
     */
    private function refreshWeeklyFromMeasurements(ProgressMeasurement $measurement): void
    {
        $project = $measurement->project()->first();

        if ($project !== null) {
            app(ProgressService::class)->refreshWeeklyActualsFromMeasurements($project);
        }
    }

    private function contractOf(Project $project): Contract
    {
        $contract = $project->contract_id === null
            ? null
            : Contract::query()->find($project->contract_id);

        if ($contract === null) {
            throw ValidationException::withMessages(['project_id' => sprintf(
                'Proyek %s belum tertaut ke kontrak; opname ke pemilik diukur per kontrak.',
                $project->code,
            )]);
        }

        return $contract;
    }

    private function contractRow(ProgressMeasurement $measurement): Contract
    {
        return Contract::query()->findOrFail($measurement->contract_id);
    }

    /**
     * The contract's BOQ: the highest-version APPROVED one, falling back to the
     * highest version of any status (an estimate is often still being approved
     * while the first opname is drafted). The contract link wins over the
     * project link — a BOQ can be priced for a project before the contract is
     * signed, and the signed one is the one whose quantities are the ceiling.
     */
    private function boqOf(Contract $contract, ?Project $project): ?Boq
    {
        foreach ([DocumentStatus::Approved->value, null] as $status) {
            $boq = Boq::query()
                ->where(function ($where) use ($contract, $project): void {
                    $where->where('contract_id', $contract->id);

                    if ($project !== null) {
                        $where->orWhere('project_id', $project->id);
                    }
                })
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                // contract_id first, then the newest revision of it.
                ->orderByRaw('CASE WHEN contract_id = ? THEN 0 ELSE 1 END', [$contract->id])
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();

            if ($boq !== null) {
                return $boq;
            }
        }

        return null;
    }

    private function assertPeriod(mixed $start, mixed $end): void
    {
        if ($start === null || $end === null) {
            return; // the FormRequest requires both on create; a partial update falls back to stored values
        }

        $start = Carbon::parse($start instanceof \DateTimeInterface ? $start->format('Y-m-d') : (string) $start);
        $end = Carbon::parse($end instanceof \DateTimeInterface ? $end->format('Y-m-d') : (string) $end);

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['period_end' => sprintf(
                'Akhir periode (%s) harus sama dengan atau setelah awal periode (%s).',
                $end->format('d-m-Y'),
                $start->format('d-m-Y'),
            )]);
        }
    }

    private function assertEditable(ProgressMeasurement $measurement): void
    {
        if (! $measurement->status->isEditable()) {
            throw new LogicException(
                "Opname {$measurement->code} berstatus {$measurement->status->value} dan tidak dapat diubah lagi."
            );
        }
    }

    /**
     * An opname an owner claim was built from is the DPP of that invoice.
     * Deleting it would leave the invoice quoting a document that is gone.
     */
    private function assertNotBilled(ProgressMeasurement $measurement): void
    {
        $invoice = DB::table('fin_ar_invoices')
            ->where('measurement_id', $measurement->id)
            ->whereNull('deleted_at')
            ->whereNot('status', DocumentStatus::Cancelled->value)
            ->value('code');

        if ($invoice !== null) {
            throw new LogicException(
                "Opname {$measurement->code} sudah dipakai invoice {$invoice}; batalkan invoicenya lebih dulu."
            );
        }
    }

    private function nextMeasurementNo(Contract $contract): int
    {
        return (int) ProgressMeasurement::query()
            ->withTrashed()
            ->where('contract_id', $contract->id)
            ->max('measurement_no') + 1;
    }

    private function qty(float $value): string
    {
        return number_format($value, 3, ',', '.');
    }
}
