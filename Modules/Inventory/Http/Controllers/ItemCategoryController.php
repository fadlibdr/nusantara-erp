<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\ItemCategoryStoreRequest;
use Modules\Inventory\Http\Requests\ItemCategoryUpdateRequest;
use Modules\Inventory\Http\Resources\ItemCategoryResource;
use Modules\Inventory\Models\ItemCategory;

class ItemCategoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ItemCategory::query()
            ->with('parent')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('parent_id'), fn ($query) => $query->where('parent_id', $request->integer('parent_id')))
            ->orderBy('code');

        return $this->listing($request, $query, ItemCategoryResource::class, sortable: ['code', 'name']);
    }

    public function store(ItemCategoryStoreRequest $request): JsonResponse
    {
        $category = ItemCategory::query()->create($request->validated());

        return $this->created(ItemCategoryResource::make($category));
    }

    public function show(ItemCategory $itemCategory): JsonResponse
    {
        return $this->ok(ItemCategoryResource::make($itemCategory->load('parent', 'children')));
    }

    public function update(ItemCategoryUpdateRequest $request, ItemCategory $itemCategory): JsonResponse
    {
        $itemCategory->update($request->validated());

        return $this->ok(ItemCategoryResource::make($itemCategory));
    }

    public function destroy(ItemCategory $itemCategory): JsonResponse
    {
        if ($itemCategory->items()->exists() || $itemCategory->children()->exists()) {
            return $this->error('Kategori masih dipakai oleh item atau sub-kategori.');
        }

        $itemCategory->delete();

        return $this->ok(null, 'Category deleted.');
    }
}
