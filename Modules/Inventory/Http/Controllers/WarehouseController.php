<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\WarehouseStoreRequest;
use Modules\Inventory\Http\Requests\WarehouseUpdateRequest;
use Modules\Inventory\Http\Resources\WarehouseResource;
use Modules\Inventory\Models\Warehouse;

class WarehouseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Warehouse::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('code');

        return $this->listing($request, $query, WarehouseResource::class, sortable: ['code', 'name', 'is_active']);
    }

    public function store(WarehouseStoreRequest $request): JsonResponse
    {
        $warehouse = Warehouse::query()->create($request->validated());

        return $this->created(WarehouseResource::make($warehouse));
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        return $this->ok(WarehouseResource::make($warehouse));
    }

    public function update(WarehouseUpdateRequest $request, Warehouse $warehouse): JsonResponse
    {
        $warehouse->update($request->validated());

        return $this->ok(WarehouseResource::make($warehouse));
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        if ($warehouse->balances()->where('qty', '>', 0)->exists()) {
            return $this->error('Gudang masih memiliki stok dan tidak dapat dihapus.');
        }

        $warehouse->delete();

        return $this->ok(null, 'Warehouse deleted.');
    }
}
