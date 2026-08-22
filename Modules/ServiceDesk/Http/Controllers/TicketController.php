<?php

namespace Modules\ServiceDesk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\ServiceDesk\Http\Requests\TicketActivityStoreRequest;
use Modules\ServiceDesk\Http\Requests\TicketAssignRequest;
use Modules\ServiceDesk\Http\Requests\TicketResolveRequest;
use Modules\ServiceDesk\Http\Requests\TicketStoreRequest;
use Modules\ServiceDesk\Http\Requests\TicketUpdateRequest;
use Modules\ServiceDesk\Http\Resources\TicketActivityResource;
use Modules\ServiceDesk\Http\Resources\TicketResource;
use Modules\ServiceDesk\Models\Ticket;
use Modules\ServiceDesk\Services\TicketService;

class TicketController extends ApiController
{
    public function __construct(private readonly TicketService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Ticket::query()
            ->with('serviceContract', 'customer', 'site')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhere('reported_by_name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('service_contract_id'), fn ($query) => $query->where('service_contract_id', $request->integer('service_contract_id')))
            ->when($request->filled('assigned_to'), fn ($query) => $query->where('assigned_to', $request->integer('assigned_to')))
            ->orderByDesc('id');

        return $this->listing($request, $query, TicketResource::class,
            sortable: ['code', 'title', 'category', 'priority', 'reported_at', 'resolution_due_at', 'status'],
            dateColumn: 'reported_at');
    }

    public function store(TicketStoreRequest $request): JsonResponse
    {
        try {
            $ticket = $this->service->create($request->validated(), $request->user()?->id);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(TicketResource::make($ticket));
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return $this->ok(TicketResource::make(
            $ticket->load('serviceContract', 'customer', 'site', 'assignee', 'activities.user', 'fieldReports.parts')
        ));
    }

    public function update(TicketUpdateRequest $request, Ticket $ticket): JsonResponse
    {
        try {
            $ticket = $this->service->update($ticket, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TicketResource::make($ticket));
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        try {
            $this->service->delete($ticket);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Ticket deleted.');
    }

    public function assign(TicketAssignRequest $request, Ticket $ticket): JsonResponse
    {
        try {
            $ticket = $this->service->assign($ticket, (int) $request->validated('employee_id'), $request->user()?->id);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TicketResource::make($ticket->load('assignee')), 'Ticket assigned.');
    }

    public function storeActivity(TicketActivityStoreRequest $request, Ticket $ticket): JsonResponse
    {
        try {
            $activity = $this->service->addActivity($ticket, $request->validated(), $request->user()?->id);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(TicketActivityResource::make($activity));
    }

    public function resolve(TicketResolveRequest $request, Ticket $ticket): JsonResponse
    {
        try {
            $ticket = $this->service->resolve($ticket, (string) $request->validated('resolution_notes'), $request->user()?->id);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TicketResource::make($ticket), 'Ticket resolved.');
    }

    public function close(Request $request, Ticket $ticket): JsonResponse
    {
        try {
            $ticket = $this->service->close($ticket, $request->user()?->id);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TicketResource::make($ticket), 'Ticket closed.');
    }

    public function slaBreaches(Request $request): JsonResponse
    {
        $query = $this->service->slaBreaches()
            ->with('serviceContract', 'customer', 'site', 'assignee')
            ->orderBy('resolution_due_at');

        return $this->ok(TicketResource::collection($query->paginate($request->integer('per_page', 20))));
    }
}
