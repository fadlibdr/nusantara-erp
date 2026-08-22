<?php

namespace Modules\Assets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assets\Http\Requests\MaintenanceStoreRequest;
use Modules\Assets\Http\Requests\MaintenanceUpdateRequest;
use Modules\Assets\Http\Resources\MaintenanceResource;
use Modules\Assets\Models\Maintenance;
use Modules\Core\Http\ApiController;

class MaintenanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Maintenance::query()
            ->with('asset.category')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%")
                        ->orWhereHas('asset', fn ($asset) => $asset->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('asset_id'), fn ($query) => $query->where('asset_id', $request->integer('asset_id')))
            ->when($request->filled('maintenance_type'), fn ($query) => $query->where('maintenance_type', $request->string('maintenance_type')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->orderByDesc('maintenance_date');

        return $this->listing($request, $query, MaintenanceResource::class,
            sortable: ['code', 'maintenance_date', 'maintenance_type', 'cost', 'next_due_date'],
            dateColumn: 'maintenance_date');
    }

    public function store(MaintenanceStoreRequest $request): JsonResponse
    {
        $maintenance = Maintenance::query()->create($request->validated());

        return $this->created(MaintenanceResource::make($maintenance->load('asset.category')));
    }

    public function show(Maintenance $maintenance): JsonResponse
    {
        return $this->ok(MaintenanceResource::make($maintenance->load('asset.category')));
    }

    public function update(MaintenanceUpdateRequest $request, Maintenance $maintenance): JsonResponse
    {
        $maintenance->update($request->validated());

        return $this->ok(MaintenanceResource::make($maintenance->load('asset.category')));
    }

    public function destroy(Maintenance $maintenance): JsonResponse
    {
        $maintenance->delete();

        return $this->ok(null, 'Maintenance record deleted.');
    }
}
