<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\MaterialVarianceReportRequest;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\MaterialVarianceService;

class MaterialVarianceController extends ApiController
{
    public function __construct(private readonly MaterialVarianceService $variance) {}

    /** Teori AHSP x volume BOQ vs bon gudang, per item per paket pekerjaan. */
    public function show(MaterialVarianceReportRequest $request, Project $project): JsonResponse
    {
        try {
            return $this->ok($this->variance->report(
                $project,
                $request->date('as_of')?->toDateString(),
                $request->string('basis', 'progress')->toString(),
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }
}
