<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\TaxEqualizationRequest;
use Modules\Finance\Services\TaxEqualizationService;

/**
 * Ekualisasi pajak — buku vs SPT, one fiscal year, four worksheets.
 *
 * Read-only from end to end: every figure is derived inside
 * TaxEqualizationService from posted journals and source documents, and the
 * residual is computed, never plugged. See the service for the decisions.
 */
class TaxEqualizationController extends ApiController
{
    public function __construct(private readonly TaxEqualizationService $equalization) {}

    public function index(TaxEqualizationRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->equalization->build($request->year()));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }
}
