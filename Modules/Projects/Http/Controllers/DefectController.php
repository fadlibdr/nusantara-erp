<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\DefectStoreRequest;
use Modules\Projects\Http\Requests\DefectUpdateRequest;
use Modules\Projects\Http\Resources\DefectResource;
use Modules\Projects\Models\Defect;
use Modules\Projects\Services\DefectService;

/**
 * Register defect (punch list / daftar temuan).
 *
 * The filters are the questions the register exists to answer and that nothing
 * could answer before: what is still open on this job, what is overdue, and
 * which of it is heavy enough to stop the serah terima.
 */
class DefectController extends ApiController
{
    /** Two relations the detail screen always shows; loading them is cheaper than N+1. */
    private const DETAIL_RELATIONS = ['project', 'wbsTask', 'responsible'];

    public function __construct(private readonly DefectService $defects) {}

    public function index(Request $request): JsonResponse
    {
        $query = Defect::query()
            ->with(['project', 'responsible'])
            ->when($request->filled('q'), fn ($query) => $query->where(function ($where) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $where->where('code', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('location', 'like', $term);
            }))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('wbs_task_id'), fn ($query) => $query->where('wbs_task_id', $request->integer('wbs_task_id')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->string('source')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('reported_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('reported_on', '<=', $request->date('to')))
            // "Apa yang masih terbuka" — including ready_for_review, which nobody
            // has accepted yet. See DefectStatus::isOpen().
            ->when($request->boolean('open'), fn ($query) => $query->whereNotIn('status', ['closed', 'waived']))
            // ONE boundary rule, shared with Defect::isOverdue(): overdue means
            // due STRICTLY BEFORE today — an item due today still has until the
            // end of the day. whereDate, not a raw `<`: the `date` cast stores
            // '2026-08-01 00:00:00' and a string compare against '2026-08-01'
            // silently changes the boundary (see the PSAK 115 whereDate saga).
            ->when($request->boolean('overdue'), fn ($query) => $query
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->whereNotIn('status', ['closed', 'waived']))
            // Heaviest first, then oldest: the order somebody walks a punch list in.
            ->orderByRaw("case severity when 'critical' then 0 when 'major' then 1 else 2 end")
            ->orderBy('reported_on');

        // The legacy from/to whens above stay for the punch-list screen;
        // date_from/date_to hit the same column via listing().
        return $this->listing($request, $query, DefectResource::class,
            sortable: ['code', 'title', 'severity', 'due_date', 'status'], dateColumn: 'reported_on');
    }

    public function store(DefectStoreRequest $request): JsonResponse
    {
        $defect = $this->defects->create($request->validated(), $request->user());

        return $this->created(DefectResource::make($defect->load(self::DETAIL_RELATIONS)));
    }

    public function show(Defect $defect): JsonResponse
    {
        return $this->ok(DefectResource::make($defect->load(self::DETAIL_RELATIONS)));
    }

    public function update(DefectUpdateRequest $request, Defect $defect): JsonResponse
    {
        try {
            // The user rides along because a severity downgrade out of
            // critical/mayor is approval-grade — see DefectService.
            $defect = $this->defects->update($defect, $request->validated(), $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(DefectResource::make($defect->load(self::DETAIL_RELATIONS)));
    }

    public function destroy(Defect $defect): JsonResponse
    {
        try {
            $this->defects->delete($defect);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Temuan dihapus.');
    }

    public function fixed(Request $request, Defect $defect): JsonResponse
    {
        try {
            $defect = $this->defects->markFixed(
                $defect,
                $request->filled('fixed_at') ? $request->date('fixed_at')->toDateString() : null,
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(DefectResource::make($defect->load(self::DETAIL_RELATIONS)), 'Temuan ditandai selesai diperbaiki, menunggu verifikasi.');
    }

    public function verify(Request $request, Defect $defect): JsonResponse
    {
        try {
            $defect = $this->defects->verify(
                $defect,
                $request->user(),
                $request->filled('verified_at') ? $request->date('verified_at')->toDateString() : null,
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(DefectResource::make($defect->load(self::DETAIL_RELATIONS)), 'Temuan diverifikasi selesai.');
    }

    public function waive(Request $request, Defect $defect): JsonResponse
    {
        try {
            $defect = $this->defects->waive(
                $defect,
                (string) $request->input('reason', ''),
                $request->user(),
                $request->filled('waived_at') ? $request->date('waived_at')->toDateString() : null,
            );
        } catch (LogicException $e) {
            // The message names the missing reason, which is the only thing that
            // tells the operator what to do next.
            return $this->error($e->getMessage());
        }

        return $this->ok(DefectResource::make($defect->load(self::DETAIL_RELATIONS)), 'Temuan diberi dispensasi.');
    }

    public function reopen(Request $request, Defect $defect): JsonResponse
    {
        try {
            $defect = $this->defects->reopen($defect, (string) $request->input('reason', ''));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(DefectResource::make($defect->load(self::DETAIL_RELATIONS)), 'Temuan dibuka kembali.');
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->ok($this->defects->summary(
            $request->filled('project_id') ? $request->integer('project_id') : null,
        ));
    }
}
