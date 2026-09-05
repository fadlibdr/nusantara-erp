<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\HealthService;

/**
 * GET api/core/health (Fase 0 / P-0b). Bergerbang core.view — bukan /up yang
 * publik: umur antrean dan jumlah job gagal adalah keadaan internal server.
 * Setiap angka yang tidak bisa dihitung adalah null; SPA menulis `?`.
 */
class HealthController extends ApiController
{
    public function __invoke(HealthService $health): JsonResponse
    {
        return $this->ok($health->report());
    }
}
