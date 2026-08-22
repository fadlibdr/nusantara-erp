<?php

namespace Modules\ServiceDesk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\ServiceDesk\Http\Requests\PreventiveScheduleStoreRequest;
use Modules\ServiceDesk\Http\Requests\PreventiveScheduleUpdateRequest;
use Modules\ServiceDesk\Http\Resources\PreventiveScheduleResource;
use Modules\ServiceDesk\Models\PreventiveSchedule;
use Modules\ServiceDesk\Services\PreventiveService;

class PreventiveScheduleController extends ApiController
{
    public function __construct(private readonly PreventiveService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = PreventiveSchedule::query()
            ->with('contract', 'site')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('service_contract_id'), fn ($query) => $query->where('service_contract_id', $request->integer('service_contract_id')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('due_before'), fn ($query) => $query->whereDate('next_due_date', '<=', $request->date('due_before')))
            ->orderBy('next_due_date');

        // The legacy due_before when above stays for the PM board; the generic
        // date pair filters the same next_due_date column.
        return $this->listing($request, $query, PreventiveScheduleResource::class,
            sortable: ['name', 'frequency', 'next_due_date', 'is_active'], dateColumn: 'next_due_date');
    }

    public function store(PreventiveScheduleStoreRequest $request): JsonResponse
    {
        $schedule = PreventiveSchedule::query()->create($request->validated());

        return $this->created(PreventiveScheduleResource::make($schedule->load('contract', 'site')));
    }

    public function show(PreventiveSchedule $schedule): JsonResponse
    {
        return $this->ok(PreventiveScheduleResource::make($schedule->load('contract', 'site', 'assignee')));
    }

    public function update(PreventiveScheduleUpdateRequest $request, PreventiveSchedule $schedule): JsonResponse
    {
        $schedule->update($request->validated());

        return $this->ok(PreventiveScheduleResource::make($schedule->load('contract', 'site')));
    }

    public function destroy(PreventiveSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return $this->ok(null, 'Preventive schedule deleted.');
    }

    public function generateNow(): JsonResponse
    {
        try {
            $created = $this->service->generateDueTickets();
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(['created' => $created], "Generated {$created} preventive ticket(s).");
    }
}
