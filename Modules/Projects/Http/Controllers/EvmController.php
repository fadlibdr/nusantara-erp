<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\EvmReportRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\EvmService;

class EvmController extends ApiController
{
    public function __construct(private readonly EvmService $evm) {}

    /** Portfolio: one row per project, including those with no baseline. */
    public function index(EvmReportRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->evm->portfolio($request->date('as_of')?->toDateString()));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show(EvmReportRequest $request, Project $project): JsonResponse
    {
        try {
            return $this->ok($this->evm->report(
                $project,
                $request->date('as_of')?->toDateString(),
                $request->integer('baseline_id') ?: null,
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }
}
