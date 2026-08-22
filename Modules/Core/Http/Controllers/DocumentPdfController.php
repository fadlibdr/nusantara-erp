<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\Response;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\DocumentPdfService;
use Modules\Finance\Models\ArInvoice;
use Modules\HrPayroll\Models\Payslip;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Projects\Models\Bast;

/**
 * Printable documents.
 *
 * Each route carries the VIEW permission of the module that owns the record —
 * printing a document is reading it, in another shape. The payslip is the one
 * worth naming: hr.view lets anybody in HR print anybody's slip, which is the
 * same reach they already have over the payroll screen it is printed from.
 *
 * Served inline so a browser opens it in its own viewer, but the SPA fetches it
 * as a blob: a plain <a href> carries no X-Api-Token header and would 401.
 */
class DocumentPdfController extends ApiController
{
    public function __construct(private readonly DocumentPdfService $pdf) {}

    public function arInvoice(ArInvoice $arInvoice): Response
    {
        return $this->stream($this->pdf->pdf('ar-invoice', $arInvoice));
    }

    public function bast(Bast $bast): Response
    {
        return $this->stream($this->pdf->pdf('bast', $bast));
    }

    public function purchaseOrder(PurchaseOrder $purchaseOrder): Response
    {
        return $this->stream($this->pdf->pdf('purchase-order', $purchaseOrder));
    }

    public function payslip(Payslip $payslip): Response
    {
        return $this->stream($this->pdf->pdf('payslip', $payslip));
    }

    private function stream(array $document): Response
    {
        return response($document['body'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($document['body']),
            'Content-Disposition' => 'inline; filename="'.$document['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            // A generated document is never worth caching: the record behind it
            // can change, and a stale invoice is worse than a slow one.
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }
}
