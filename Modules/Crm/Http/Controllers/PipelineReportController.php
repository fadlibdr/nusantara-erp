<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\ApiController;
use Modules\Crm\Services\PipelineReportService;

/**
 * Analitik win-rate tender (temuan #78) — agregasi murni-baca atas
 * won_at/lost_at/lost_reason yang sudah dicatat QuotationService.
 */
class PipelineReportController extends ApiController
{
    public function __construct(private readonly PipelineReportService $service) {}

    public function pipeline(): JsonResponse
    {
        return $this->ok($this->service->report());
    }
}
