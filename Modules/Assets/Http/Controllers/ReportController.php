<?php

namespace Modules\Assets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Assets\Services\DeploymentService;
use Modules\Core\Http\ApiController;

class ReportController extends ApiController
{
    public function __construct(private readonly DeploymentService $service) {}

    /**
     * GET reports/utilization?project_id=&from=&to=
     * Days deployed per asset per project + suggested internal equipment charge.
     */
    public function utilization(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return $this->ok($this->service->utilization(
            $request->filled('project_id') ? $request->integer('project_id') : null,
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        ));
    }
}
