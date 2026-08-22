<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Support\SegregationOfDuties;
use Modules\Projects\Enums\BacSource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Models\WbsTask;
use Modules\Projects\Support\PlannedCurve;

/**
 * Snapshot, freeze and supersede project baselines.
 *
 * A baseline that can be quietly rewritten proves nothing, so everything here
 * bends towards one property: what was agreed stays readable, forever, exactly
 * as it was agreed. update() and delete() refuse on anything approved, the
 * table carries no soft deletes to route around that, and approving revision N
 * writes ONLY superseded_at + superseded_by_id onto its predecessor — never a
 * byte of its content. The chain from revision 0 forward is append-only.
 *
 * BAC RESOLUTION MIRRORS THE POC ENGINE, VALUE FOR VALUE. The order is the same
 * one RevenueRecognitionService::estimateTotalCost applies: approved RAP first,
 * then the newest still in workflow, and a REJECTED RAP is never eligible —
 * "a rejected budget is nobody's estimate of anything" applies just as hard to
 * a baseline as it does to revenue. That is not academic here: the demo's only
 * RAP, RAP/2026/0001 for Rp 42.173.913.043,47, is 'submitted', so every EVM
 * figure on the live site carries the rap_unapproved flag.
 */
class BaselineService
{
    /** Leaf weights are allowed to miss 100% by this much before refusal. */
    private const WEIGHT_TOLERANCE = 0.01;

    // ---------------------------------------------------------------- create

    /**
     * Freeze the live WBS + RAP into a DRAFT baseline.
     */
    public function snapshot(Project $project, array $data, ?User $by = null): ProjectBaseline
    {
        return DB::transaction(function () use ($project, $data, $by): ProjectBaseline {
            $revision = $this->nextRevision($project);

            // A re-baseline without a stated reason is the quiet rewrite in
            // slow motion: six months later nobody can say whether the finish
            // date moved because of an approved extension of time or because
            // somebody found the old plan inconvenient.
            if ($revision > 0 && trim((string) ($data['reason'] ?? '')) === '') {
                throw new LogicException(
                    "Baseline revisi {$revision} untuk {$project->code} wajib menyebutkan alasan "
                    .'(mis. CCO, addendum, atau perpanjangan waktu yang disetujui).'
                );
            }

            $baseline = new ProjectBaseline([
                'project_id' => $project->id,
                'revision_no' => $revision,
                'status' => DocumentStatus::Draft,
                'effective_date' => $data['effective_date'] ?? now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'curve_source' => ProjectBaseline::CURVE_SOURCE_WBS,
                'created_by' => $by?->id,
            ]);

            $this->fillSnapshot($baseline, $project, $data['bac_override'] ?? null);

            try {
                $baseline->save();
            } catch (QueryException $e) {
                throw $this->translateRevisionClash($e, $revision);
            }

            $this->writeTasksAndPoints($baseline, $project);

            return $baseline->refresh();
        });
    }

    /**
     * Re-read the live WBS and RAP into an existing DRAFT.
     *
     * Draft-only. Re-snapshotting an approved baseline would rewrite the plan
     * under every report already issued against it.
     */
    public function resnapshot(ProjectBaseline $baseline): ProjectBaseline
    {
        $this->assertEditable($baseline, 'diambil ulang');

        return DB::transaction(function () use ($baseline): ProjectBaseline {
            $project = $baseline->project()->firstOrFail();

            $this->fillSnapshot($baseline, $project, $this->overrideOf($baseline));
            $baseline->save();

            $baseline->tasks()->delete();
            $baseline->points()->delete();
            $this->writeTasksAndPoints($baseline, $project);

            return $baseline->refresh();
        });
    }

    /**
     * Header fields, draft/rejected only. The one edit that touches frozen
     * content is bac_override, and it re-freezes the tasks and the curve in the
     * same breath — a baseline whose header and points disagree about BAC is
     * worse than either number alone.
     */
    public function update(ProjectBaseline $baseline, array $data): ProjectBaseline
    {
        $this->assertEditable($baseline, 'diubah');

        $fields = array_intersect_key($data, array_flip([
            'effective_date', 'reason', 'reference_type', 'reference_no', 'notes',
        ]));

        return DB::transaction(function () use ($baseline, $data, $fields): ProjectBaseline {
            $baseline->fill($fields);

            if (array_key_exists('bac_override', $data) && $data['bac_override'] !== null) {
                $project = $baseline->project()->firstOrFail();
                $this->fillSnapshot($baseline, $project, (float) $data['bac_override']);
                $baseline->save();

                // The frozen points price every planned percentage in rupiah of
                // BAC, so a new BAC means a new curve — rewriting the header
                // alone left the final point reading 100% = Rp 42.173.913.043,47
                // under a header claiming Rp 60.000.000.000, and once approved
                // that contradiction was permanent. Same rewrite resnapshot()
                // does, in the same transaction.
                $baseline->tasks()->delete();
                $baseline->points()->delete();
                $this->writeTasksAndPoints($baseline, $project);
            } else {
                $baseline->save();
            }

            return $baseline->refresh();
        });
    }

    public function delete(ProjectBaseline $baseline): void
    {
        if ($baseline->isFrozen()) {
            throw new LogicException(
                "Baseline {$baseline->code} sudah disetujui dan tidak dapat dihapus — "
                .'inilah yang membuatnya bernilai sebagai bukti.'
            );
        }

        // Same maker-checker line assertEditable() draws: deleting a document
        // out of the approver's queue is the loudest possible edit.
        if (! $baseline->status->isEditable()) {
            throw new LogicException(
                "Baseline {$baseline->code} sedang menunggu persetujuan dan tidak dapat dihapus. "
                .'Minta penolakan lebih dulu bila memang batal.'
            );
        }

        DB::transaction(function () use ($baseline): void {
            $baseline->tasks()->delete();
            $baseline->points()->delete();
            $baseline->approvals()->delete();
            $baseline->delete();
        });
    }

    // ------------------------------------------------------------- lifecycle

    public function submit(ProjectBaseline $baseline, ?User $by = null): ProjectBaseline
    {
        $this->assertStatus($baseline, [DocumentStatus::Draft, DocumentStatus::Rejected], 'diajukan');

        $baseline->forceFill(['status' => DocumentStatus::Submitted])->save();
        $this->recordApproval($baseline, 'submitted', $by);

        return $baseline->refresh();
    }

    /**
     * Freeze it. The predecessor is superseded in the same transaction.
     *
     * Maker-checker: the person who took the snapshot may not be the one who
     * freezes it. Same guard every other document in this system gets, and for
     * the same reason — a plan one person can both propose and ratify is a plan
     * with one person's word behind it.
     */
    public function approve(ProjectBaseline $baseline, User $by, ?string $note = null): ProjectBaseline
    {
        $this->assertStatus($baseline, [DocumentStatus::Submitted], 'disetujui');
        SegregationOfDuties::assertNotSubmitter($baseline, $by);

        return DB::transaction(function () use ($baseline, $by, $note): ProjectBaseline {
            // Re-read INSIDE the transaction rather than trusting the instance
            // the caller is holding: lockForUpdate() is a no-op on SQLite, so a
            // baseline approved by somebody else a moment ago must be seen here
            // and not superseded by a stale copy of itself.
            $previous = ProjectBaseline::query()
                ->where('project_id', $baseline->project_id)
                ->where('id', '!=', $baseline->id)
                ->where('status', DocumentStatus::Approved->value)
                ->whereNull('superseded_at')
                ->orderByDesc('revision_no')
                ->get();

            $baseline->forceFill([
                'status' => DocumentStatus::Approved,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            foreach ($previous as $old) {
                // ONLY these two columns. Its content is evidence.
                $old->forceFill([
                    'superseded_at' => now(),
                    'superseded_by_id' => $baseline->id,
                ])->save();
            }

            $this->recordApproval($baseline, 'approved', $by, $note);

            return $baseline->refresh();
        });
    }

    public function reject(ProjectBaseline $baseline, User $by, ?string $note = null): ProjectBaseline
    {
        $this->assertStatus($baseline, [DocumentStatus::Submitted], 'ditolak');

        $baseline->forceFill(['status' => DocumentStatus::Rejected])->save();
        $this->recordApproval($baseline, 'rejected', $by, $note);

        return $baseline->refresh();
    }

    // --------------------------------------------------------------- reading

    /** The one baseline reports measure against: approved, not superseded. */
    public function currentFor(Project $project, ?int $baselineId = null): ?ProjectBaseline
    {
        $query = ProjectBaseline::query()
            ->where('project_id', $project->id)
            ->where('status', DocumentStatus::Approved->value);

        if ($baselineId !== null) {
            return $query->whereKey($baselineId)->first();
        }

        return $query->whereNull('superseded_at')->orderByDesc('revision_no')->first();
    }

    /** Revision 0 — the plan at contract signature, and never deletable. */
    public function originalFor(Project $project): ?ProjectBaseline
    {
        return ProjectBaseline::query()
            ->where('project_id', $project->id)
            ->where('revision_no', 0)
            ->where('status', DocumentStatus::Approved->value)
            ->first();
    }

    // -------------------------------------------------------------- snapshot

    /**
     * Fill the frozen scalars from the live project, RAP and WBS.
     */
    private function fillSnapshot(ProjectBaseline $baseline, Project $project, ?float $override): void
    {
        $leaves = $this->leafTasks($project);
        $this->assertPlannable($project, $leaves);

        [$bac, $source, $rap] = $this->resolveBac($project, $override);

        [$plannedStart, $plannedFinish] = PlannedCurve::window($leaves);

        $contract = $project->contract()->first();

        $baseline->fill([
            'bac' => round($bac, 2),
            'bac_source' => $source,
            'cost_budget_id' => $rap?->id,
            'cost_budget_code' => $rap?->code,
            'cost_budget_status' => $rap?->status,
            'contract_id' => $project->contract_id,
            'contract_code' => $contract?->code,
            'contract_value' => round((float) $project->contract_value, 2),
            'planned_start' => $plannedStart,
            'planned_finish' => $plannedFinish,
            'contract_finish' => $project->end_date?->toDateString(),
            'planned_duration_days' => (int) Carbon::parse($plannedStart)->diffInDays(Carbon::parse($plannedFinish)) + 1,
            'leaf_task_count' => $leaves->count(),
            'leaf_weight_total' => round((float) $leaves->sum(fn (WbsTask $task): float => (float) $task->weight_pct), 4),
        ]);
    }

    private function writeTasksAndPoints(ProjectBaseline $baseline, Project $project): void
    {
        // Parents are frozen too, so the tree can be displayed years later
        // without joining a live table that may no longer hold those rows.
        $tasks = $project->wbsTasks()->orderBy('sort_order')->orderBy('wbs_code')->get();
        $parentCodes = $tasks->pluck('wbs_code', 'id');
        $leafIds = $this->leafTasks($project)->pluck('id')->all();

        foreach ($tasks as $task) {
            $baseline->tasks()->create([
                'wbs_task_id' => $task->id,
                'wbs_code' => $task->wbs_code,
                'parent_wbs_code' => $task->parent_id !== null ? ($parentCodes[$task->parent_id] ?? null) : null,
                'name' => $task->name,
                'is_leaf' => in_array($task->id, $leafIds, true),
                'weight_pct' => $task->weight_pct,
                'planned_start' => $task->planned_start?->toDateString(),
                'planned_end' => $task->planned_end?->toDateString(),
                'sort_order' => (int) $task->sort_order,
            ]);
        }

        foreach (PlannedCurve::monthlyPoints($this->leafTasks($project), (float) $baseline->bac) as $point) {
            $baseline->points()->create($point);
        }
    }

    /**
     * The same resolution order as RevenueRecognitionService::estimateTotalCost.
     *
     * @return array{0: float, 1: BacSource, 2: object|null}
     */
    private function resolveBac(Project $project, ?float $override): array
    {
        $rap = DB::table('est_cost_budgets')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'submitted', 'draft'])
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();

        if ($rap !== null && (float) $rap->total_budget <= 0) {
            $rap = null;
        }

        if ($override !== null) {
            if ($override <= 0) {
                throw new LogicException('BAC harus lebih besar dari nol.');
            }

            return [(float) $override, BacSource::Override, $rap];
        }

        if ($rap === null) {
            // Where EVM and PSAK 115 deliberately diverge. Para 45 lets Finance
            // keep recognising revenue at zero margin with no estimate at all;
            // EVM has no such fallback, because substituting the contract value
            // would turn CPI into a margin ratio and label it cost performance.
            // PRJ-2026-002 is exactly this case in the live data.
            throw new LogicException(
                "Proyek {$project->code} belum punya RAP; baseline tidak dapat dibekukan "
                .'karena anggaran biaya (BAC) tidak ada. Susun RAP lebih dulu.'
            );
        }

        return [
            (float) $rap->total_budget,
            $rap->status === 'approved' ? BacSource::RapApproved : BacSource::RapUnapproved,
            $rap,
        ];
    }

    /**
     * A plan you cannot measure against is not a plan.
     *
     * @param  Collection<int, WbsTask>  $leaves
     */
    private function assertPlannable(Project $project, $leaves): void
    {
        if ($leaves->isEmpty()) {
            throw new LogicException(
                "Proyek {$project->code} belum punya WBS; baseline tidak dapat dibekukan tanpa struktur pekerjaan."
            );
        }

        $total = round((float) $leaves->sum(fn (WbsTask $task): float => (float) $task->weight_pct), 4);

        if (abs($total - 100) > self::WEIGHT_TOLERANCE) {
            throw new LogicException(sprintf(
                'Bobot tugas daun berjumlah %s%%, bukan 100%%; perbaiki WBS sebelum membekukan baseline.',
                number_format($total, 4, ',', '.'),
            ));
        }

        foreach ($leaves as $leaf) {
            if ($leaf->planned_start === null || $leaf->planned_end === null) {
                throw new LogicException(
                    "Tugas {$leaf->wbs_code} belum punya tanggal rencana mulai dan selesai; "
                    .'kurva rencana tidak dapat dibentuk tanpa keduanya.'
                );
            }

            if ($leaf->planned_end->lt($leaf->planned_start)) {
                throw new LogicException(
                    "Tugas {$leaf->wbs_code} selesai sebelum mulai ("
                    .$leaf->planned_start->toDateString().' → '.$leaf->planned_end->toDateString().').'
                );
            }
        }
    }

    /** @return Collection<int, WbsTask> */
    private function leafTasks(Project $project)
    {
        return $project->wbsTasks()->whereDoesntHave('children')->orderBy('sort_order')->orderBy('wbs_code')->get();
    }

    private function nextRevision(Project $project): int
    {
        $max = ProjectBaseline::query()->where('project_id', $project->id)->max('revision_no');

        return $max === null ? 0 : ((int) $max + 1);
    }

    private function overrideOf(ProjectBaseline $baseline): ?float
    {
        return $baseline->bac_source === BacSource::Override ? (float) $baseline->bac : null;
    }

    // ---------------------------------------------------------------- guards

    /**
     * Editable means draft or rejected — SUBMITTED is refused too. A submitted
     * baseline sits in the approver's queue, and a maker who can rewrite its
     * content there defeats maker-checker entirely: the approval trail would
     * still read submitted → approved while the frozen curve moved in between,
     * indistinguishable from an honest review. Rejection is the one road back
     * to the maker, exactly as every other document lifecycle here requires.
     */
    private function assertEditable(ProjectBaseline $baseline, string $action): void
    {
        if ($baseline->isFrozen()) {
            throw new LogicException(
                "Baseline {$baseline->code} sudah disetujui dan tidak dapat {$action}. "
                .'Buat revisi baru bila rencana memang berubah.'
            );
        }

        if (! $baseline->status->isEditable()) {
            throw new LogicException(
                "Baseline {$baseline->code} sedang menunggu persetujuan dan tidak dapat {$action}. "
                .'Minta penolakan lebih dulu bila isinya memang perlu diubah.'
            );
        }
    }

    private function assertStatus(ProjectBaseline $baseline, array $allowed, string $action): void
    {
        if (! in_array($baseline->status, $allowed, true)) {
            throw new LogicException(
                "Baseline {$baseline->code} tidak dapat {$action} saat berstatus {$baseline->status->label()}."
            );
        }
    }

    /**
     * unique(project_id, revision_no) is the real concurrency defence; this
     * turns its constraint violation into something an operator can act on.
     */
    private function translateRevisionClash(QueryException $e, int $revision): LogicException|QueryException
    {
        $message = strtolower($e->getMessage());

        if (! str_contains($message, 'unique') && ! str_contains($message, 'constraint')) {
            return $e;
        }

        return new LogicException("Baseline revisi {$revision} sudah dibuat oleh pengguna lain; muat ulang halaman.");
    }

    private function recordApproval(ProjectBaseline $baseline, string $action, ?User $by, ?string $note = null): void
    {
        $baseline->approvals()->create([
            'action' => $action,
            'user_id' => $by?->id,
            'note' => $note,
        ]);

        DocumentTransitioned::dispatch($baseline, $action, $by, $note);
    }
}
