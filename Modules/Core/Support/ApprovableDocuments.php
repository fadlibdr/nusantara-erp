<?php

namespace Modules\Core\Support;

use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Quotation;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Payment;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractAddendum;

/**
 * The seventeen documents that go through submit → approve/reject, and the three
 * things a notification needs to know about each: who may approve it, what to
 * call it, and where to send the reader.
 *
 * One explicit table rather than convention. Deriving the permission prefix
 * from the namespace would be shorter and would silently produce "estimation."
 * for a module whose permissions are "est." — and the failure mode of a wrong
 * prefix is a notification sent to nobody, which nobody notices.
 */
class ApprovableDocuments
{
    /** @var array<class-string, array{prefix: string, label: string, resource: string}> */
    private const MAP = [
        Quotation::class => ['prefix' => 'crm', 'label' => 'Penawaran', 'resource' => 'crm/quotations'],
        ContractChangeOrder::class => ['prefix' => 'crm', 'label' => 'Pekerjaan tambah-kurang', 'resource' => 'crm/contract-change-orders'],
        Boq::class => ['prefix' => 'est', 'label' => 'BOQ / RAB', 'resource' => 'estimation/boqs'],
        CostBudget::class => ['prefix' => 'est', 'label' => 'RAP', 'resource' => 'estimation/cost-budgets'],
        Bast::class => ['prefix' => 'prj', 'label' => 'BAST', 'resource' => 'projects/bast'],
        // Like Payment below, ProjectBaseline does not use the Approvable trait
        // — BaselineService walks the identical lifecycle by hand — but it goes
        // through the same submit → approve stage. Absent from this registry,
        // NotificationService::documentSubmitted returned early and no
        // prj.approve holder was ever told a baseline was waiting.
        ProjectBaseline::class => ['prefix' => 'prj', 'label' => 'Baseline proyek', 'resource' => 'projects/baselines'],
        PurchaseRequisition::class => ['prefix' => 'prc', 'label' => 'Permintaan pembelian', 'resource' => 'procurement/purchase-requisitions'],
        PurchaseOrder::class => ['prefix' => 'prc', 'label' => 'Pesanan pembelian', 'resource' => 'procurement/purchase-orders'],
        StockAdjustment::class => ['prefix' => 'inv', 'label' => 'Penyesuaian stok', 'resource' => 'inventory/stock-adjustments'],
        Subcontract::class => ['prefix' => 'scm', 'label' => 'SPK subkontraktor', 'resource' => 'subcontract/subcontracts'],
        // Registered so submit notifications reach scm.approve holders like
        // every other approvable. The addendum's DIRECTOR gate stays inside
        // AddendumService — see assertDirectorMayBeNeeded there for why it
        // does not ride Procurement's DirectorApproval.
        SubcontractAddendum::class => ['prefix' => 'scm', 'label' => 'Addendum SPK', 'resource' => 'subcontract/addenda'],
        ProgressClaim::class => ['prefix' => 'scm', 'label' => 'Opname subkon', 'resource' => 'subcontract/progress-claims'],
        ArInvoice::class => ['prefix' => 'fin', 'label' => 'Invoice termin', 'resource' => 'finance/ar-invoices'],
        ApBill::class => ['prefix' => 'fin', 'label' => 'Tagihan vendor', 'resource' => 'finance/ap-bills'],
        // Payment does not use the Approvable trait (its status is a different
        // enum), but it goes through the same submit → approve stage and must
        // therefore notify the same fin.approve holders and be named the same
        // way when maker-checker refuses it.
        Payment::class => ['prefix' => 'fin', 'label' => 'Pembayaran keluar', 'resource' => 'finance/payments'],
        PayrollRun::class => ['prefix' => 'hr', 'label' => 'Payroll', 'resource' => 'hr/payroll-runs'],
        LeaveRequest::class => ['prefix' => 'hr', 'label' => 'Pengajuan cuti', 'resource' => 'hr/leave-requests'],
    ];

    public static function knows(object|string $document): bool
    {
        return isset(self::MAP[is_string($document) ? $document : $document::class]);
    }

    /** The permission whose holders may approve this kind of document. */
    public static function approvePermission(object|string $document): ?string
    {
        $entry = self::entry($document);

        return $entry === null ? null : $entry['prefix'].'.approve';
    }

    public static function label(object|string $document): string
    {
        return self::entry($document)['label'] ?? 'Dokumen';
    }

    /**
     * SPA hash route to the document's DETAIL screen.
     *
     * "#/d/…", not "#/r/…". The router uses r/ for a resource list and d/ for
     * one record, and a link to the list route with an id appended matches
     * neither — it renders "halaman tidak dikenal", which is what every
     * notification link did until somebody clicked one.
     */
    public static function link(object $document): ?string
    {
        $entry = self::entry($document);

        return $entry === null ? null : "#/d/{$entry['resource']}/{$document->getKey()}";
    }

    /** @return array<class-string, array{prefix: string, label: string, resource: string}> */
    public static function all(): array
    {
        return self::MAP;
    }

    private static function entry(object|string $document): ?array
    {
        return self::MAP[is_string($document) ? $document : $document::class] ?? null;
    }
}
