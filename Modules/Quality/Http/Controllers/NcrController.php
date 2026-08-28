<?php

namespace Modules\Quality\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Quality\Http\Requests\NcrStoreRequest;
use Modules\Quality\Http\Requests\NcrUpdateRequest;
use Modules\Quality\Http\Resources\NcrResource;
use Modules\Quality\Models\Ncr;
use Modules\Quality\Services\NcrService;

/**
 * NCR endpoints. The lifecycle actions (start-correction / verify / close) are
 * NcrStatus transitions, not the Approvable cycle — verify is qc.approve
 * (accepting a correction is approve-adjacent power), the rest qc.update.
 */
class NcrController extends ApiController
{
    public function __construct(private readonly NcrService $service) {}

    private const DETAIL = [
        'project', 'inspection', 'location', 'responsibleEmployee', 'subcontract', 'verifier',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Ncr::query()
            ->with(['project', 'location'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, NcrResource::class,
            sortable: ['code', 'due_date', 'status'],
            dateColumn: 'due_date');
    }

    public function store(NcrStoreRequest $request): JsonResponse
    {
        $ncr = $this->service->create($request->validated());

        return $this->created(NcrResource::make($ncr->load(self::DETAIL)));
    }

    public function show(Ncr $ncr): JsonResponse
    {
        return $this->ok(NcrResource::make($ncr->load(self::DETAIL)));
    }

    public function update(NcrUpdateRequest $request, Ncr $ncr): JsonResponse
    {
        $updated = $this->service->update($ncr, $request->validated());

        return $this->ok(NcrResource::make($updated->load(self::DETAIL)));
    }

    public function destroy(Ncr $ncr): JsonResponse
    {
        $ncr->delete();

        return $this->ok(null, 'NCR dihapus.');
    }

    public function startCorrection(Ncr $ncr): JsonResponse
    {
        try {
            $this->service->startCorrection($ncr);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(NcrResource::make($ncr->load(self::DETAIL)), 'Perbaikan NCR dimulai.');
    }

    public function verify(Request $request, Ncr $ncr): JsonResponse
    {
        try {
            $this->service->verify($ncr, $request->user(), $request->input('verified_at'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(NcrResource::make($ncr->load(self::DETAIL)), 'NCR terverifikasi.');
    }

    public function close(Ncr $ncr): JsonResponse
    {
        try {
            $this->service->close($ncr);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(NcrResource::make($ncr->load(self::DETAIL)), 'NCR ditutup.');
    }
}
