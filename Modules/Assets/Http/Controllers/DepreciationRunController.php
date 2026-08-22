<?php

namespace Modules\Assets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Modules\Assets\Enums\DepreciationRunStatus;
use Modules\Assets\Http\Requests\DepreciationRunStoreRequest;
use Modules\Assets\Http\Resources\DepreciationRunResource;
use Modules\Assets\Models\DepreciationRun;
use Modules\Assets\Services\DepreciationService;
use Modules\Core\Http\ApiController;

class DepreciationRunController extends ApiController
{
    public function __construct(private readonly DepreciationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = DepreciationRun::query()
            ->withCount('entries')
            ->when($request->filled('year'), fn ($query) => $query->where('period_year', $request->integer('year')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month');

        return $this->listing($request, $query, DepreciationRunResource::class,
            // Bukan period_year/period_month: layarnya merender SATU kolom
            // 'period' hasil gabungan, jadi tombol urut keduanya tidak pernah
            // muncul. Kronologi tetap tersedia lewat posted_at.
            sortable: ['code', 'total_amount', 'posted_at', 'status']);
    }

    public function store(DepreciationRunStoreRequest $request): JsonResponse
    {
        try {
            $run = $this->service->runForPeriod(
                $request->integer('year'),
                $request->integer('month'),
            );
        } catch (InvalidArgumentException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(DepreciationRunResource::make($run), 'Depreciation run drafted.');
    }

    public function show(DepreciationRun $depreciationRun): JsonResponse
    {
        return $this->ok(DepreciationRunResource::make($depreciationRun->load('entries.asset.category')));
    }

    public function destroy(DepreciationRun $depreciationRun): JsonResponse
    {
        if ($depreciationRun->status !== DepreciationRunStatus::Draft) {
            return $this->error("Run {$depreciationRun->periodLabel()} sudah diposting dan tidak dapat dihapus.");
        }

        $depreciationRun->entries()->delete();
        $depreciationRun->delete();

        return $this->ok(null, 'Draft depreciation run deleted.');
    }

    public function post(DepreciationRun $depreciationRun): JsonResponse
    {
        try {
            $run = $this->service->post($depreciationRun);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            DepreciationRunResource::make($run),
            'Depreciation posted; asset book values updated. Finance can now import this run as a journal.'
        );
    }
}
