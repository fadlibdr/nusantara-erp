<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\TaxExportRequest;
use Modules\Finance\Services\TaxExportService;

/**
 * Statutory tax exports: e-Faktur (PPN keluaran) and e-Bupot (PPh dipotong).
 *
 * The CSV is returned inside the JSON envelope rather than as a file download,
 * because the client authenticates with a token header — a plain <a download>
 * carries no headers and would be rejected. The browser writes the file from
 * the returned text, which also lets the screen show the operator exactly what
 * is in it before anything reaches DJP's importer.
 */
class TaxExportController extends ApiController
{
    public function __construct(private readonly TaxExportService $exports) {}

    public function eFaktur(TaxExportRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->exports->eFaktur($request->year(), $request->month()));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function eBupot(TaxExportRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->exports->eBupot($request->year(), $request->month()));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Both exports for one period, so the screen can show output VAT and
     * withholding side by side without two round trips.
     */
    public function index(TaxExportRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->exports->overview($request->year(), $request->month()));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Terbitkan nomor bukti potong for one masa — the one write in this lane,
     * and a POST because of it.
     *
     * A bukti potong number is a permanent legal reference the vendor cites
     * when claiming its PPh credit. Bills approved since the number existed
     * already carry one; this is the operator-driven catch-up for a masa whose
     * bills predate it, and it is idempotent — a bill that already has a number
     * keeps it and is reported under already_numbered.
     */
    public function issueBuktiPotongNumbers(TaxExportRequest $request): JsonResponse
    {
        try {
            $result = $this->exports->issueBuktiPotongNumbers($request->year(), $request->month());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($result, sprintf(
            '%d nomor bukti potong diterbitkan untuk masa %s.',
            $result['summary']['issued'],
            $result['period']['label'],
        ));
    }
}
