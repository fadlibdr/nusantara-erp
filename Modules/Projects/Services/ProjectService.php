<?php

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\NotificationService;
use Modules\Core\Support\Erp;
use Modules\Crm\Models\Contract;
use Modules\Estimation\Models\Boq;
use Modules\Projects\Enums\BastType;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Enums\ProjectType;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;

class ProjectService
{
    public function __construct(
        private readonly ProgressService $progress,
        private readonly BastPrerequisiteService $prerequisites,
        private readonly NotificationService $notifications,
        private readonly DailyReportService $dailyReports,
    ) {}

    public function create(array $data): Project
    {
        // A contract reference bootstraps the project from the contract data.
        if (! empty($data['contract_id'])) {
            $contract = Contract::query()->find($data['contract_id']);

            if ($contract) {
                return $this->createFromContract($contract, Arr::except($data, ['code', 'status']));
            }
        }

        return DB::transaction(function () use ($data): Project {
            $data['retention_pct'] = $data['retention_pct']
                ?? Erp::float('projects.default_retention_pct', 5.0);
            $data['status'] = ProjectStatus::Preparation;

            return Project::query()->create(Arr::except($data, ['code']));
        });
    }

    /**
     * Bootstrap a project from a signed CRM contract: value (DPP), dates,
     * retention and warranty carry over; explicit overrides win.
     */
    public function createFromContract(Contract $contract, array $overrides = []): Project
    {
        return DB::transaction(function () use ($contract, $overrides): Project {
            $defaults = [
                'contract_id' => $contract->id,
                'customer_id' => $contract->customer_id,
                'name' => $contract->title,
                'type' => $this->mapScopeType($contract),
                'contract_value' => round((float) $contract->value, 2),
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'retention_pct' => (float) $contract->retention_pct,
                'warranty_months' => (int) $contract->warranty_months,
                'status' => ProjectStatus::Preparation,
            ];

            return Project::query()->create(array_merge($defaults, $overrides));
        });
    }

    public function update(Project $project, array $data): Project
    {
        // A null status is the edit form's empty select (its list carries no
        // 'Ditutup'), and means "do not touch the status" — never a write to
        // the NOT NULL column.
        if (array_key_exists('status', $data) && $data['status'] === null) {
            unset($data['status']);
        }

        // 'Ditutup' is not reachable from the dropdown. It used to be: one PUT
        // by anybody holding prj.update closed PRJ-2026-001 over Rp 9,7 miliar
        // of unbilled termins with nothing in the way. Closing now goes through
        // ProjectClosureService (prj.approve + open-items checklist) or a BAST
        // II approval. LEAVING closed by ordinary update stays allowed, on
        // purpose: there is no undo anywhere else, and the asymmetry is the
        // protection — any later close must re-earn the checklist.
        if (array_key_exists('status', $data)) {
            $incoming = $data['status'] instanceof ProjectStatus
                ? $data['status']
                : ProjectStatus::tryFrom((string) $data['status']);

            if ($incoming === ProjectStatus::Closed && $project->status !== ProjectStatus::Closed) {
                throw new LogicException(
                    "Proyek {$project->code} tidak dapat ditutup lewat ubah status biasa;"
                    .' gunakan aksi Tutup proyek, yang memeriksa item terbuka (defect, PO, termin, retensi) lebih dulu.'
                );
            }
        }

        return DB::transaction(function () use ($project, $data): Project {
            $project->fill(Arr::except($data, ['code', 'actual_progress_pct']))->save();

            return $project->refresh();
        });
    }

    /**
     * Geser tanggal selesai proyek mengikuti addendum waktu kontraknya (P0-B).
     *
     * Called by ContractChangeOrderService::approve INSIDE its transaction —
     * the project row is re-read under lock here (the TOCTOU idiom this
     * codebase uses everywhere), so the status the gate reads is the status
     * the write happens under, and a refusal rolls the whole approval back.
     *
     * THE GATE: a project in masa pemeliharaan or ditutup has been handed
     * over — its deadline is history, and "extending" it is a different
     * instrument (a warranty arrangement or a new contract), not a CCO. The
     * refusal names the status, per the assertOperational precedent. On-hold
     * projects pass deliberately: a suspension is exactly why a time addendum
     * gets signed.
     *
     * Null when the contract has not been opened as a project yet — the
     * contract's own end_date still moves; there is simply no project copy to
     * keep in step.
     */
    public function shiftEndDateForContract(int $contractId, Carbon $newEndDate): ?Project
    {
        /** @var Project|null $project */
        $project = Project::query()
            ->where('contract_id', $contractId)
            ->lockForUpdate()
            ->first();

        if ($project === null) {
            return null;
        }

        if (in_array($project->status, [ProjectStatus::Warranty, ProjectStatus::Closed], true)) {
            throw new LogicException(sprintf(
                'Proyek %s berstatus %s; addendum waktu hanya berlaku atas pekerjaan yang masih berjalan — '
                .'perpanjangan setelah serah terima adalah instrumen lain.',
                $project->code,
                $project->status->label(),
            ));
        }

        $project->forceFill(['end_date' => $newEndDate->toDateString()])->save();

        return $project;
    }

    public function delete(Project $project): void
    {
        if ($project->status === ProjectStatus::Active) {
            throw new LogicException("Project {$project->code} is active; put it on hold or close it before deleting.");
        }

        $project->delete();
    }

    /**
     * Build the WBS from the linked BOQ: sections become parent tasks, items
     * become leaf tasks weighted by cost share (amount / BOQ total * 100).
     * The last leaf absorbs the rounding residue so leaf weights sum to exactly 100.
     */
    public function generateWbsFromBoq(Project $project, ?int $boqId = null): Project
    {
        // Pintu yang paling ganas ke proyek tutup: bukan satu entri progres
        // melainkan hapus-dan-bangun-ulang seluruh WBS, me-reset
        // actual_progress_pct proyek yang sudah 100%. Penjaga yang sama dengan
        // laporan harian dan progres — pintu ini sempat terlewat.
        $project->assertOperational('generate WBS');

        $boq = match (true) {
            $boqId !== null => Boq::query()->find($boqId),
            $project->boq_id !== null => Boq::query()->find($project->boq_id),
            default => Boq::query()->where('project_id', $project->id)->orderByDesc('id')->first(),
        };

        if (! $boq) {
            throw new LogicException("No BOQ found for project {$project->code}; link a BOQ before generating the WBS.");
        }

        $boq->loadMissing('sections.items');

        $total = 0.0;
        $leafCount = 0;

        foreach ($boq->sections as $section) {
            foreach ($section->items as $item) {
                $total += (float) $item->amount;
                $leafCount++;
            }
        }

        if ($leafCount === 0 || round($total, 2) <= 0) {
            throw new LogicException("BOQ {$boq->code} has no priced items; cannot derive WBS weights.");
        }

        return DB::transaction(function () use ($project, $boq, $total, $leafCount): Project {
            // Replace any existing WBS — children first (self-referencing FK).
            $project->wbsTasks()->whereNotNull('parent_id')->delete();
            $project->wbsTasks()->delete();

            $allocated = 0.0;
            $index = 0;

            foreach ($boq->sections as $sectionIndex => $section) {
                $parent = $project->wbsTasks()->create([
                    'parent_id' => null,
                    'boq_item_id' => null,
                    'wbs_code' => $section->section_no,
                    'name' => $section->name,
                    'weight_pct' => 0, // filled below from its children
                    'planned_start' => $project->start_date?->toDateString(),
                    'planned_end' => $project->end_date?->toDateString(),
                    'progress_pct' => 0,
                    'sort_order' => $sectionIndex + 1,
                ]);

                $parentWeight = 0.0;

                foreach ($section->items as $itemIndex => $item) {
                    $index++;

                    $weight = $index === $leafCount
                        ? round(100 - $allocated, 4)
                        : round((float) $item->amount / $total * 100, 4);
                    $allocated = round($allocated + $weight, 4);
                    $parentWeight = round($parentWeight + $weight, 4);

                    $project->wbsTasks()->create([
                        'parent_id' => $parent->id,
                        'boq_item_id' => $item->id,
                        'wbs_code' => $item->wbs_code,
                        'name' => $item->description,
                        'weight_pct' => $weight,
                        'planned_start' => $project->start_date?->toDateString(),
                        'planned_end' => $project->end_date?->toDateString(),
                        'progress_pct' => 0,
                        'sort_order' => $itemIndex + 1,
                    ]);
                }

                $parent->forceFill(['weight_pct' => $parentWeight])->save();
            }

            $project->forceFill([
                'boq_id' => $project->boq_id ?? $boq->id,
                'actual_progress_pct' => 0, // fresh plan, progress restarts from the WBS
            ])->save();

            return $project->refresh();
        });
    }

    /**
     * Create a BAST. For BAST I the retention release due date defaults to
     * handover date + warranty months (end of masa pemeliharaan).
     */
    public function createBast(array $data): Bast
    {
        $project = Project::query()->findOrFail($data['project_id']);

        $type = $data['bast_type'] instanceof BastType
            ? $data['bast_type']
            : BastType::from((string) $data['bast_type']);

        if (empty($data['retention_release_due']) && $type === BastType::Bast1) {
            // NoOverflow: masa pemeliharaan is counted in calendar months, so a
            // handover on 31-08 + 6 months must clamp to 28/29-02, not spill
            // over into March the way Carbon's addMonths() does.
            $data['retention_release_due'] = Carbon::parse($data['handover_date'])
                ->addMonthsNoOverflow((int) $project->warranty_months)
                ->toDateString();
        }

        $data['status'] = DocumentStatus::Draft;

        return Bast::query()->create(Arr::except($data, ['code']));
    }

    /**
     * Approving BAST I moves the project into the warranty period (masa
     * pemeliharaan); approving BAST II closes the project — and releases the
     * customer's retensi, which is why only BAST II is gated.
     *
     * THE CHECKLIST RUNS ONLY ON A BAST II THAT IS ALREADY SUBMITTED. A draft
     * must still fail with "while status is draft": that is the more fundamental
     * error and the message several suites assert verbatim, and it is the same
     * ordering argument Approvable makes for its own maker-checker guard.
     */
    public function approveBast(Bast $bast, User $by, ?string $note = null, ?string $overrideReason = null): Bast
    {
        $overrideReason = $overrideReason === null || trim($overrideReason) === '' ? null : trim($overrideReason);

        // Both handovers run the checklist once submitted — BAST II the full
        // gate in front of the retensi, BAST I the P1-QC "no open NCR" block
        // (BastPrerequisiteService::evaluate branches on the type). A draft
        // still fails inside approve() with "while status is draft", the more
        // fundamental error, before any prerequisite is read.
        $evaluation = $bast->status === DocumentStatus::Submitted
            ? $this->prerequisites->assertApprovable($bast, $overrideReason)
            : null;

        $bast = DB::transaction(function () use ($bast, $by, $note, $overrideReason, $evaluation): Bast {
            $usedOverride = $overrideReason !== null && ($evaluation['needs_override'] ?? false);

            // The reason goes into the approval note as well as its own column,
            // so core_approvals — the timeline an auditor actually reads — carries
            // it beside the click it excuses.
            $bast->approve($by, $usedOverride
                ? trim(($note === null || $note === '' ? '' : $note.' ')."Prasyarat dilewati: {$overrideReason}")
                : $note);

            $project = $bast->project;

            if ($bast->bast_type === BastType::Bast1) {
                $project->forceFill([
                    'status' => ProjectStatus::Warranty,
                    'actual_end_date' => $project->actual_end_date?->toDateString()
                        ?? $bast->handover_date?->toDateString(),
                ])->save();

                // P0-A: serah terima yang ditandatangani membekukan laporan
                // harian bertanggal ≤ tanggal serah terima — riwayat yang
                // diserahkan tiga pihak berhenti menjadi draf. Cakupan dan
                // alasannya di DailyReportService::lockForApprovedBastOne.
                $this->dailyReports->lockForApprovedBastOne($bast);

                return $bast->refresh();
            }

            // closed_at/closed_by so "kapan dan oleh siapa proyek ini ditutup"
            // has one answer whichever door closed it. The checklist THIS path
            // ran is snapshotted on the BAST below (prerequisite_snapshot);
            // closure_snapshot on the project belongs to the Tutup proyek
            // action and stays null here.
            $project->forceFill([
                'status' => ProjectStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
            ])->save();

            $stamp = [];

            if ($evaluation !== null) {
                $stamp['prerequisite_snapshot'] = $evaluation;
            }

            if ($usedOverride) {
                $stamp['prerequisite_override_reason'] = $overrideReason;
                $stamp['prerequisite_override_by'] = $by->id;
                $stamp['prerequisite_override_at'] = now();
            }

            // Written at APPROVAL, never at creation, so a draft cannot move
            // Finance's date — and NEVER when it would land EARLIER than the
            // release date BAST I already promised. "The max wins downstream"
            // used to be the whole defence here, and it holds only while a later
            // BAST I date exists to take the max against: with the BAST I date
            // nulled (or warranty_months 0) there was nothing to max against and
            // an early BAST II published Rp 2.425.000.000 as collectible about
            // twelve months ahead of contract. An early BAST II now stamps
            // nothing — BAST I's own date keeps standing — while a BAST II on a
            // project whose BAST I carries no date at all still stamps, because
            // that gap now costs a recorded override (see the masa_pemeliharaan
            // warning) and leaving it null would make the retensi permanently
            // uncollectible in ArRetentionService.
            $firstDue = Bast::query()
                ->where('project_id', $bast->project_id)
                ->where('bast_type', BastType::Bast1->value)
                ->where('status', DocumentStatus::Approved->value)
                ->max('retention_release_due');
            // SQLite hands the `date` column back as '2027-12-20 00:00:00'.
            $firstDue = $firstDue === null ? null : substr((string) $firstDue, 0, 10);

            if ($bast->retention_release_due === null && $bast->handover_date !== null
                && ($firstDue === null || $bast->handover_date->toDateString() >= $firstDue)) {
                $stamp['retention_release_due'] = $bast->handover_date->toDateString();
            }

            if ($stamp !== []) {
                $bast->forceFill($stamp)->save();
            }

            return $bast->refresh();
        });

        if ($bast->isBast2()) {
            $this->announceCollectibleRetention($bast, $by);
        }

        return $bast;
    }

    /**
     * Tell whoever raises invoices that the retensi can now be billed.
     *
     * Same audience and same argument as MilestoneService: fin.create is the
     * permission that actually creates an AR invoice, so it is the smallest set
     * of people who can act on this. On CTR/2026/I/0001 the sum is
     * Rp 2.425.000.000 sitting on an unbilled termin — the identical shape of
     * handoff that left Termin 2's Rp 14,55 miliar uninvoiced for four months.
     *
     * Announced OUTSIDE the transaction, and NotificationService swallows its own
     * failures: a mail server that is down may not roll back a handover.
     */
    private function announceCollectibleRetention(Bast $bast, User $by): void
    {
        $project = $bast->project;

        if ($project === null) {
            return;
        }

        $snapshot = $bast->prerequisite_snapshot;
        $amount = (float) ($snapshot['retention_at_stake'] ?? $project->retentionAmount());
        $value = 'Rp '.number_format($amount, 0, ',', '.');
        $contractId = $project->contract_id;

        $this->notifications->system(
            'fin.create',
            "Retensi proyek {$project->code} dapat ditagih — {$value}",
            sprintf(
                'BAST II %s disetujui %s oleh %s. Masa pemeliharaan proyek %s — %s berakhir; retensi senilai %s sudah dapat ditagihkan.',
                $bast->code,
                $bast->handover_date?->format('d-m-Y') ?? now()->format('d-m-Y'),
                $by->name,
                $project->code,
                $project->name,
                $value,
            ),
            $contractId === null ? "#/d/projects/{$project->id}" : "#/d/crm/contracts/{$contractId}",
        );
    }

    /**
     * Site dashboard snapshot: progress vs plan, manpower on site today,
     * milestone alerts and the open PO count from Procurement (when migrated).
     */
    public function dashboard(Project $project): array
    {
        $today = now()->toDateString();

        $manpowerToday = $project->manpowerAssignments()
            ->where('is_active', true)
            ->whereDate('assigned_from', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('assigned_until')
                    ->orWhereDate('assigned_until', '>=', $today);
            })
            ->count();

        $latestDaily = $project->dailyReports()->orderByDesc('report_date')->first();
        // reorder() drops the relation's default ascending week_no sort, which
        // would otherwise win and make this the EARLIEST week (see ProgressService).
        $latestWeek = $project->weeklyProgress()->reorder()->orderByDesc('week_no')->first();
        $nextMilestone = $project->milestones()->whereNull('achieved_date')->orderBy('due_date')->first();
        $overdueMilestones = $project->milestones()
            ->whereNull('achieved_date')
            ->whereDate('due_date', '<', $today)
            ->count();

        $leafWeightTotal = (float) $project->wbsTasks()->whereDoesntHave('children')->sum('weight_pct');
        $openDefects = $project->openDefects()->get();

        return [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status?->value,
                'status_label' => $project->status?->label(),
            ],
            'progress' => [
                'planned_pct' => (float) $project->planned_progress_pct,
                'actual_pct' => (float) $project->actual_progress_pct,
                'deviation_pct' => $project->progressDeviation(),
            ],
            'manpower_today' => $manpowerToday,
            'latest_daily_report' => $latestDaily ? [
                'code' => $latestDaily->code,
                'report_date' => $latestDaily->report_date?->toDateString(),
                'manpower_count' => (int) $latestDaily->manpower_count,
            ] : null,
            'latest_week' => $latestWeek ? [
                'week_no' => (int) $latestWeek->week_no,
                'planned_pct' => (float) $latestWeek->planned_pct,
                'actual_pct' => (float) $latestWeek->actual_pct,
                'deviation_pct' => (float) $latestWeek->deviation_pct,
            ] : null,
            'milestones' => [
                'overdue_count' => $overdueMilestones,
                'next' => $nextMilestone ? [
                    'name' => $nextMilestone->name,
                    'due_date' => $nextMilestone->due_date?->toDateString(),
                ] : null,
            ],
            'wbs' => [
                'task_count' => $project->wbsTasks()->count(),
                'leaf_weight_total' => round($leafWeightTotal, 4), // sanity: should be 100
            ],
            // Punch list. open_blocking_count is the one that matters late in a
            // job: it is exactly the number that will refuse BAST II.
            'defects' => [
                'open_count' => $openDefects->count(),
                'open_blocking_count' => $openDefects
                    ->filter(fn (Defect $defect): bool => $defect->severity->blocksHandover())
                    ->count(),
                'overdue_count' => $openDefects
                    ->filter(fn (Defect $defect): bool => $defect->isOverdue())
                    ->count(),
            ],
            'open_po_count' => $this->openPurchaseOrderCount($project),
        ];
    }

    /**
     * Open PO count comes from the Procurement module (prc_purchase_orders,
     * project_id + status per the shared-ID contract). Counted only once that
     * module's table is migrated; 0 otherwise.
     */
    private function openPurchaseOrderCount(Project $project): int
    {
        return $this->openPurchaseOrders($project)['count'];
    }

    /**
     * The same fact with the codes attached, public so ProjectClosureService
     * can NAME the POs its refusal is about instead of re-deriving the query.
     * "Open" means draft, submitted or approved — anything not yet delivered
     * and closed, exactly what the dashboard's "PO terbuka" tile counts.
     *
     * @return array{count: int, codes: array<int, string>}
     */
    public function openPurchaseOrders(Project $project): array
    {
        if (! Schema::hasTable('prc_purchase_orders')
            || ! Schema::hasColumn('prc_purchase_orders', 'project_id')
            || ! Schema::hasColumn('prc_purchase_orders', 'status')) {
            return ['count' => 0, 'codes' => []];
        }

        $query = DB::table('prc_purchase_orders')
            ->where('project_id', $project->id)
            ->whereIn('status', [
                DocumentStatus::Draft->value,
                DocumentStatus::Submitted->value,
                DocumentStatus::Approved->value,
            ]);

        if (Schema::hasColumn('prc_purchase_orders', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return [
            'count' => (int) $query->count(),
            'codes' => $query->orderBy('code')->limit(5)->pluck('code')->all(),
        ];
    }

    /**
     * Crm ScopeType and ProjectType share the same backing values by design.
     */
    private function mapScopeType(Contract $contract): ProjectType
    {
        $value = $contract->scope_type instanceof \BackedEnum
            ? $contract->scope_type->value
            : (string) $contract->scope_type;

        return ProjectType::tryFrom($value) ?? ProjectType::Construction;
    }
}
