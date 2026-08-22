<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\SafetyIncidentStoreRequest;
use Modules\Projects\Http\Requests\SafetyIncidentUpdateRequest;
use Modules\Projects\Http\Resources\SafetyIncidentResource;
use Modules\Projects\Models\SafetyIncident;
use Modules\Projects\Services\SafetyIncidentService;

/**
 * Register kecelakaan kerja (SMK3).
 *
 * The filters are the questions the assessment said could not be asked: every
 * incident in a quarter, everything still open, everything overdue, everything
 * of a given severity.
 */
class SafetyIncidentController extends ApiController
{
    public function __construct(private readonly SafetyIncidentService $incidents) {}

    public function index(Request $request): JsonResponse
    {
        $query = SafetyIncident::query()
            ->with(['project', 'responsible'])
            ->when($request->filled('q'), fn ($query) => $query->where(function ($where) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $where->where('code', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('location', 'like', $term);
            }))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('occurred_at', '<=', $request->date('to')))
            // An overdue corrective action is the one thing a site manager is
            // asked about in a safety walk, so it is a filter, not a scroll.
            ->when($request->boolean('overdue'), fn ($query) => $query
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->toDateString())
                ->where('status', '!=', 'closed'))
            ->when($request->boolean('open'), fn ($query) => $query->where('status', '!=', 'closed'))
            ->orderByDesc('occurred_at');

        // The legacy from/to whens above stay for the K3 screen; date_from/
        // date_to land on the same column via listing() for the generic list.
        return $this->listing($request, $query, SafetyIncidentResource::class,
            sortable: ['code', 'occurred_at', 'severity', 'category', 'lost_days', 'status'], dateColumn: 'occurred_at');
    }

    public function store(SafetyIncidentStoreRequest $request): JsonResponse
    {
        $incident = $this->incidents->create($request->validated(), $request->user());

        return $this->created(SafetyIncidentResource::make($incident->load(['project', 'responsible'])));
    }

    public function show(SafetyIncident $safetyIncident): JsonResponse
    {
        return $this->ok(SafetyIncidentResource::make($safetyIncident->load(['project', 'responsible'])));
    }

    public function update(SafetyIncidentUpdateRequest $request, SafetyIncident $safetyIncident): JsonResponse
    {
        try {
            $incident = $this->incidents->update($safetyIncident, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(SafetyIncidentResource::make($incident->load(['project', 'responsible'])));
    }

    public function destroy(SafetyIncident $safetyIncident): JsonResponse
    {
        $safetyIncident->delete();

        return $this->ok(null, 'Insiden dihapus.');
    }

    public function close(Request $request, SafetyIncident $safetyIncident): JsonResponse
    {
        try {
            $incident = $this->incidents->close(
                $safetyIncident,
                $request->user(),
                $request->filled('closed_at') ? $request->date('closed_at')->toDateString() : null,
            );
        } catch (LogicException $e) {
            // The message names exactly which of root cause / corrective action /
            // owner is still missing, so it is worth putting in front of the user
            // rather than collapsing into a generic refusal.
            return $this->error($e->getMessage());
        }

        return $this->ok(SafetyIncidentResource::make($incident->load(['project', 'responsible'])), 'Insiden ditutup.');
    }

    public function reopen(SafetyIncident $safetyIncident): JsonResponse
    {
        try {
            $incident = $this->incidents->reopen($safetyIncident);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(SafetyIncidentResource::make($incident->load(['project', 'responsible'])), 'Insiden dibuka kembali.');
    }

    /** Laporan K3 — the monthly report a project owes its client. */
    public function statistics(Request $request): JsonResponse
    {
        $to = $request->filled('to') ? $request->date('to') : now();
        $from = $request->filled('from') ? $request->date('from') : $to->copy()->startOfMonth();

        return $this->ok($this->incidents->statistics(
            $request->filled('project_id') ? $request->integer('project_id') : null,
            $from->toDateString(),
            $to->toDateString(),
        ));
    }
}
