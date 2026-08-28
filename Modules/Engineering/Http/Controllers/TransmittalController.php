<?php

namespace Modules\Engineering\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Engineering\Http\Requests\TransmittalReceiveRequest;
use Modules\Engineering\Http\Requests\TransmittalStoreRequest;
use Modules\Engineering\Http\Requests\TransmittalUpdateRequest;
use Modules\Engineering\Http\Resources\TransmittalResource;
use Modules\Engineering\Models\Transmittal;
use Modules\Engineering\Services\TransmittalService;

class TransmittalController extends ApiController
{
    public function __construct(private readonly TransmittalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Transmittal::query()
            ->with('project')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('to_party', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', $request->string('direction')))
            ->orderByDesc('id');

        return $this->listing($request, $query, TransmittalResource::class,
            sortable: ['code', 'transmittal_date', 'direction', 'to_party'],
            dateColumn: 'transmittal_date');
    }

    public function store(TransmittalStoreRequest $request): JsonResponse
    {
        $transmittal = $this->service->create($request->validated(), $request->user());

        return $this->created(TransmittalResource::make($transmittal->load(['project', 'lines.document'])));
    }

    public function show(Transmittal $transmittal): JsonResponse
    {
        return $this->ok(TransmittalResource::make($transmittal->load(['project', 'lines.document', 'createdBy'])));
    }

    public function update(TransmittalUpdateRequest $request, Transmittal $transmittal): JsonResponse
    {
        $updated = $this->service->update($transmittal, $request->validated());

        return $this->ok(TransmittalResource::make($updated->load(['project', 'lines.document'])));
    }

    public function destroy(Transmittal $transmittal): JsonResponse
    {
        $this->service->delete($transmittal);

        return $this->ok(null, 'Transmittal dihapus.');
    }

    /** Tanda terima: who signed for the bundle, when. */
    public function terima(TransmittalReceiveRequest $request, Transmittal $transmittal): JsonResponse
    {
        $received = $this->service->receive($transmittal, $request->validated());

        return $this->ok(
            TransmittalResource::make($received->load(['project', 'lines.document'])),
            'Tanda terima dicatat.',
        );
    }
}
