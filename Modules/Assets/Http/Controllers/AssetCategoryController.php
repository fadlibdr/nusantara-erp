<?php

namespace Modules\Assets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assets\Http\Requests\AssetCategoryStoreRequest;
use Modules\Assets\Http\Requests\AssetCategoryUpdateRequest;
use Modules\Assets\Http\Resources\AssetCategoryResource;
use Modules\Assets\Models\AssetCategory;
use Modules\Core\Http\ApiController;

class AssetCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AssetCategory::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->orderBy('code');

        return $this->listing($request, $query, AssetCategoryResource::class,
            sortable: ['code', 'name', 'useful_life_months_default']);
    }

    public function store(AssetCategoryStoreRequest $request): JsonResponse
    {
        $category = AssetCategory::query()->create($request->validated());

        return $this->created(AssetCategoryResource::make($category));
    }

    public function show(AssetCategory $category): JsonResponse
    {
        return $this->ok(AssetCategoryResource::make($category));
    }

    public function update(AssetCategoryUpdateRequest $request, AssetCategory $category): JsonResponse
    {
        $category->update($request->validated());

        return $this->ok(AssetCategoryResource::make($category));
    }

    public function destroy(AssetCategory $category): JsonResponse
    {
        if ($category->assets()->exists()) {
            return $this->error('Kategori masih memiliki aset dan tidak dapat dihapus.');
        }

        $category->delete();

        return $this->ok(null, 'Category deleted.');
    }
}
