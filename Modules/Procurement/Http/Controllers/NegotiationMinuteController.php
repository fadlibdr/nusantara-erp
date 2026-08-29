<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\NegotiationMinuteStoreRequest;
use Modules\Procurement\Http\Requests\NegotiationMinuteUpdateRequest;
use Modules\Procurement\Http\Resources\NegotiationMinuteResource;
use Modules\Procurement\Models\NegotiationMinute;
use Modules\Procurement\Services\NegotiationMinuteService;

class NegotiationMinuteController extends ApiController
{
    public function __construct(private readonly NegotiationMinuteService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = NegotiationMinute::query()
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('rfq_id'), fn ($q) => $q->where('rfq_id', $request->integer('rfq_id')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->with(['vendor', 'rfq'])
            ->orderByDesc('id');

        return $this->listing($request, $query, NegotiationMinuteResource::class,
            sortable: ['code', 'meeting_date'], dateColumn: 'meeting_date');
    }

    public function store(NegotiationMinuteStoreRequest $request): JsonResponse
    {
        try {
            $minute = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(NegotiationMinuteResource::make($minute));
    }

    public function show(NegotiationMinute $negotiationMinute): JsonResponse
    {
        return $this->ok(NegotiationMinuteResource::make(
            $negotiationMinute->load('items', 'vendor', 'rfq')
        ));
    }

    public function update(NegotiationMinuteUpdateRequest $request, NegotiationMinute $negotiationMinute): JsonResponse
    {
        try {
            $minute = $this->service->update($negotiationMinute, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(NegotiationMinuteResource::make($minute));
    }

    public function destroy(NegotiationMinute $negotiationMinute): JsonResponse
    {
        $this->service->delete($negotiationMinute);

        return $this->ok(null, 'BA Negosiasi dihapus.');
    }
}
