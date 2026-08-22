<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Services\PoService;

class ReportController extends ApiController
{
    public function __construct(private readonly PoService $service) {}

    /**
     * GET reports/outstanding?vendor_id=&project_id=
     * Baris PO terbuka (qty_received < qty pada PO approved), telat duluan.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
        ]);

        return $this->ok($this->service->outstandingLines(
            $request->filled('vendor_id') ? $request->integer('vendor_id') : null,
            $request->filled('project_id') ? $request->integer('project_id') : null,
        ));
    }
}
