<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Resources\ProjectCostResource;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\ProjectCostService;

class ProjectCostController extends ApiController
{
    public function __construct(private readonly ProjectCostService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProjectCost::query()
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('cost_category'), fn ($query) => $query->where('cost_category', $request->string('cost_category')))
            ->orderByDesc('cost_date')
            ->orderByDesc('id');

        // Jendela tanggal lokal dihapus — listing() memasang predikat identik
        // pada cost_date. totals_by_category ikut lewat parameter meta, digabung
        // dengan meta paginasi/sort alih-alih menggantikannya seperti dulu.
        return $this->listing($request, $query, ProjectCostResource::class,
            sortable: ['cost_date', 'cost_category', 'reference_type', 'amount'],
            dateColumn: 'cost_date',
            meta: $request->filled('project_id')
                ? ['totals_by_category' => $this->service->totalsByCategory($request->integer('project_id'))]
                : [],
        );
    }
}
