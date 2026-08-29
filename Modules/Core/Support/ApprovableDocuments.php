<?php

namespace Modules\Core\Support;

use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Quotation;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Payment;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Procurement\Models\AwardDecision;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Models\OvertimePermit;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Models\WorkPermit;
use Modules\Quality\Models\Inspection;
use Modules\Subcontract\Models\Handover;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Models\LaborContract;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractAddendum;

/**
 * Every document that goes through submit → approve/reject, and the three
 * things a notification needs to know about each: who may approve it, what to
 * call it, and where to send the reader.
 *
 * (The header used to name a count. It was wrong by three the moment P1 landed
 * and wrong by five after P3, because nothing recomputes prose — so the count
 * is gone rather than re-broken.)
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
        /*
         * P0-C — the three field permits. All three approve on prj.approve:
         * the spec names it for IKL outright; for ILB the pad's own
         * "Menyetujui" column is the Manajer Proyek, and prj.approve is what
         * the project-manager role holds in RoleSeeder; for IMK the pass is a
         * prj_ document approved by site management before the gate checks it
         * (the periksa act is prj.update — checking is not a second approval),
         * and CONVENTIONS §6 derives the permission from the table prefix.
         */
        WorkPermit::class => ['prefix' => 'prj', 'label' => 'Izin kerja lapangan', 'resource' => 'projects/work-permits'],
        OvertimePermit::class => ['prefix' => 'prj', 'label' => 'Izin kerja lembur', 'resource' => 'projects/overtime-permits'],
        GatePass::class => ['prefix' => 'prj', 'label' => 'Izin masuk/keluar material', 'resource' => 'projects/gate-passes'],
        /*
         * P1-ENG — the ONE Engineering approvable. The submittals (SDS/SMS)
         * are deliberately NOT here: their four-stamp decision belongs to the
         * external MK and is recorded as fact through the module's own
         * decision endpoints, not walked through submit → approve — an entry
         * here would notify eng.approve holders to "approve" a sheet that is
         * not theirs to approve.
         */
        WorkPermitIpp::class => ['prefix' => 'eng', 'label' => 'Ijin pelaksanaan pekerjaan', 'resource' => 'engineering/ipp'],
        /*
         * P1-QC — the ONE Quality approvable. The NCR is deliberately NOT here:
         * its lifecycle is NcrStatus (open → under_correction → verified →
         * closed), not submit → approve, so an entry here would notify
         * qc.approve holders to "approve" a report that is never approved. The
         * concrete sample/test carry no approval cycle at all.
         */
        Inspection::class => ['prefix' => 'qc', 'label' => 'Inspeksi mutu', 'resource' => 'quality/inspections'],
        /*
         * P3 — the owner opname. Registered like every other approvable so a
         * submit notifies prj.approve holders; the MK's own signature arrives
         * through ExternalApprovableDocuments in TRANSITION mode on top of this
         * internal cycle, not instead of it. The BAPP zona is deliberately NOT
         * here: its status is ZoneCertificateStatus (done/check/waiting_repair),
         * a record of what an inspector saw, so an entry would ask prj.approve
         * holders to "approve" a sheet nobody approves.
         */
        ProgressMeasurement::class => ['prefix' => 'prj', 'label' => 'Opname progres owner', 'resource' => 'projects/progress-measurements'],
        PurchaseRequisition::class => ['prefix' => 'prc', 'label' => 'Permintaan pembelian', 'resource' => 'procurement/purchase-requisitions'],
        PurchaseOrder::class => ['prefix' => 'prc', 'label' => 'Pesanan pembelian', 'resource' => 'procurement/purchase-orders'],
        /*
         * P2 — the award decision is the ONE new procurement approvable, and the
         * first document in the app that rides the n-level ladder: approve()
         * counts distinct approvers and levels 2+ demand prc.approve-director
         * (Core\Support\ApprovalLevels, config('erp.approvals.award_decision')).
         * Registered here so submit notifies prc.approve holders like every
         * other approvable — the ladder is enforced inside Approvable::approve,
         * not by a second registry.
         */
        AwardDecision::class => ['prefix' => 'prc', 'label' => 'Keputusan pemenang', 'resource' => 'procurement/award-decisions'],
        StockAdjustment::class => ['prefix' => 'inv', 'label' => 'Penyesuaian stok', 'resource' => 'inventory/stock-adjustments'],
        Subcontract::class => ['prefix' => 'scm', 'label' => 'SPK subkontraktor', 'resource' => 'subcontract/subcontracts'],
        // Registered so submit notifications reach scm.approve holders like
        // every other approvable. The addendum's DIRECTOR gate stays inside
        // AddendumService — see assertDirectorMayBeNeeded there for why it
        // does not ride Procurement's DirectorApproval.
        SubcontractAddendum::class => ['prefix' => 'scm', 'label' => 'Addendum SPK', 'resource' => 'subcontract/addenda'],
        ProgressClaim::class => ['prefix' => 'scm', 'label' => 'Opname subkon', 'resource' => 'subcontract/progress-claims'],
        // P3 — BAST subkon I/II. scm.approve, the same right that approves the
        // SPK: BAST I starts the masa pemeliharaan the retention we hold
        // secures, and BAST II ends it.
        Handover::class => ['prefix' => 'scm', 'label' => 'BAST subkontraktor', 'resource' => 'subcontract/handovers'],
        // P4 — SP3 mandor dan opname mandornya. scm.approve seperti dokumen
        // Subcontract lain; opname adalah dokumen yang mengubah volume jadi
        // uang terhutang, jadi ia ber-maker-checker penuh seperti opname subkon.
        LaborContract::class => ['prefix' => 'scm', 'label' => 'SP3 mandor', 'resource' => 'subcontract/labor-contracts'],
        LaborClaim::class => ['prefix' => 'scm', 'label' => 'Opname mandor', 'resource' => 'subcontract/labor-claims'],
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
