<?php

namespace Modules\Estimation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Estimation\Http\Requests\AhspStoreRequest;
use Modules\Estimation\Http\Requests\AhspUpdateRequest;
use Modules\Estimation\Http\Resources\AhspResource;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Services\AhspService;

class AhspController extends ApiController
{
    public function __construct(private readonly AhspService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Ahsp::query()
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($w) use ($term): void {
                    $w->where('code', 'like', $term)->orWhere('name', 'like', $term);
                });
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderBy('code');

        return $this->listing($request, $query, AhspResource::class,
            sortable: ['code', 'name', 'category', 'overhead_pct', 'unit_price']);
    }

    public function store(AhspStoreRequest $request): JsonResponse
    {
        $ahsp = $this->service->create($request->validated());

        return $this->created(new AhspResource($ahsp->load('components')));
    }

    public function show(Ahsp $ahsp): JsonResponse
    {
        return $this->ok(new AhspResource($ahsp->load('components')));
    }

    public function update(AhspUpdateRequest $request, Ahsp $ahsp): JsonResponse
    {
        $ahsp = $this->service->update($ahsp, $request->validated());

        return $this->ok(new AhspResource($ahsp->load('components')));
    }

    public function destroy(Ahsp $ahsp): JsonResponse
    {
        if ($ahsp->boqItems()->exists()) {
            return $this->error("AHSP {$ahsp->code} is referenced by BOQ items and cannot be deleted.", 409);
        }

        $ahsp->delete();

        return $this->ok(null, 'Deleted');
    }
}
