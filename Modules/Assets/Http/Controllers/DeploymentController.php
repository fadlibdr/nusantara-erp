<?php

namespace Modules\Assets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Assets\Http\Requests\DeploymentReturnRequest;
use Modules\Assets\Http\Requests\DeploymentStoreRequest;
use Modules\Assets\Http\Requests\DeploymentUpdateRequest;
use Modules\Assets\Http\Resources\DeploymentResource;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Services\DeploymentService;
use Modules\Core\Http\ApiController;

class DeploymentController extends ApiController
{
    public function __construct(private readonly DeploymentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Deployment::query()
            ->with('asset.category')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhereHas('asset', fn ($asset) => $asset->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('asset_id'), fn ($query) => $query->where('asset_id', $request->integer('asset_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('deployed_from');

        return $this->listing($request, $query, DeploymentResource::class,
            sortable: ['code', 'deployed_from', 'planned_until', 'daily_rate_internal', 'status'],
            dateColumn: 'deployed_from');
    }

    public function store(DeploymentStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $asset = Asset::query()->findOrFail($data['asset_id']);

        try {
            $deployment = $this->service->deploy($asset, $data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(DeploymentResource::make($deployment));
    }

    public function show(Deployment $deployment): JsonResponse
    {
        // equipmentLogs ride along so the reading history is visible where
        // the machine is — the generic detail screen draws them as a table.
        return $this->ok(DeploymentResource::make($deployment->load([
            'asset.category',
            'equipmentLogs' => fn ($query) => $query->with('loggedBy')->orderByDesc('log_date')->orderByDesc('id'),
        ])));
    }

    public function update(DeploymentUpdateRequest $request, Deployment $deployment): JsonResponse
    {
        $deployment->update($request->validated());

        return $this->ok(DeploymentResource::make($deployment->load('asset.category')));
    }

    public function destroy(Deployment $deployment): JsonResponse
    {
        if ($deployment->isActive()) {
            return $this->error("Mobilisasi {$deployment->code} masih aktif; kembalikan aset terlebih dahulu.");
        }

        $deployment->delete();

        return $this->ok(null, 'Deployment deleted.');
    }

    public function return(DeploymentReturnRequest $request, Deployment $deployment): JsonResponse
    {
        $data = $request->validated();

        try {
            $deployment = $this->service->returnDeployment(
                $deployment,
                $data['returned_at'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(DeploymentResource::make($deployment), 'Asset returned from project.');
    }
}
