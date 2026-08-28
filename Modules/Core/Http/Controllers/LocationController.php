<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Requests\LocationStoreRequest;
use Modules\Core\Http\Requests\LocationUpdateRequest;
use Modules\Core\Http\Resources\LocationResource;
use Modules\Core\Models\Location;

/**
 * P1-ENG: CRUD for the site breakdown. The hierarchy rules (same project, no
 * cycle, no orphaning) live on the Location model itself — see the model for
 * why — so this controller stays thin and the master-data importer is held to
 * the same rules without knowing they exist.
 */
class LocationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Location::query()
            ->with('parent')
            ->withCount('children')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')))
            ->when($request->filled('parent_id'), fn ($query) => $query->where('parent_id', $request->integer('parent_id')))
            ->orderBy('sort_order')->orderBy('code');

        return $this->listing($request, $query, LocationResource::class,
            sortable: ['code', 'name', 'kind', 'sort_order']);
    }

    public function store(LocationStoreRequest $request): JsonResponse
    {
        $location = Location::query()->create(
            $request->validated() + ['sort_order' => $request->integer('sort_order', 0)],
        );

        return $this->created(LocationResource::make($location->load('parent')));
    }

    public function show(Location $location): JsonResponse
    {
        return $this->ok(LocationResource::make($location->load('parent')->loadCount('children')));
    }

    public function update(LocationUpdateRequest $request, Location $location): JsonResponse
    {
        $location->fill($request->validated())->save();

        return $this->ok(LocationResource::make($location->load('parent')));
    }

    public function destroy(Location $location): JsonResponse
    {
        $location->delete(); // the model's deleting hook refuses while children exist

        return $this->ok(null, 'Lokasi dihapus.');
    }
}
