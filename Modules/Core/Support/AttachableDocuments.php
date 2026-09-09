<?php

namespace Modules\Core\Support;

use Modules\Assets\Models\Asset;
use Modules\Core\Models\MethodLibraryEntry;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\TenderPackage;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\MaterialSubmittal;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PettyCashVoucher;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Certificate;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Procurement\Models\NegotiationMinute;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorDocument;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\SafetyIncident;
use Modules\Projects\Models\WorkPermit;
use Modules\Quality\Models\Inspection;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\Ticket;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\Subcontract;

/**
 * Which documents may carry attachments, addressed by SLUG.
 *
 * The slug is what crosses the wire — "finance/ap-bills", the same vocabulary
 * the SPA already uses for its routes. The class name never does. That is the
 * whole point: an endpoint that accepted a class name would let a caller name
 * any class in the application as the parent of a file, and the difference
 * between a validated allowlist and an arbitrary string here is the difference
 * between an attachment feature and an object-injection surface.
 *
 * The permission prefix is per module, so attaching to a vendor bill needs
 * fin.update and reading one needs fin.view — the same rights as editing and
 * reading the bill itself. An attachment is part of the document; it must not
 * be easier to reach than the document.
 *
 * `table` IS A LITERAL STRING, AND THAT IS THE POINT (F-8).
 *
 * The expiry watcher (WatchedDeadlines, entries attachment_valid_until_*) runs
 * on core_attachments — one polymorphic table pointing at forty others by class
 * name. It must decide two things per row that only this registry knows: which
 * module's permission the row answers to, and whether the document it hangs off
 * still exists. Both are answered HERE, in Core, from literals — never by
 * booting a feature-module model to ask it for its table, and never by a query
 * a feature module owns. The literals are pinned by AttachmentRegistryTest
 * against the real models, so a table rename in another team's lane fails a
 * test instead of silently emptying a watcher's scope. This is the same rule
 * WatchedDeadlines itself lives by; the exception this file already carries is
 * the class-name imports, which exist because slug → class is what makes the
 * upload endpoint an allowlist instead of an object-injection surface.
 */
class AttachableDocuments
{
    /** @var array<string, array{class: class-string, prefix: string, label: string, table: string}> */
    private const MAP = [
        /*
         * P7 — the metode pelaksanaan library. THIS is the document P0-D's
         * pptx/docx policy was pinned for: a metode pelaksanaan arrives as a
         * slide deck or a Word file, and until now nothing in the registry
         * could carry one. est.*, not core.*, for the reason spelled out on
         * migration 000191 — the estimator writes the method, and core.* is
         * held only by admin and direktur.
         *
         * The attachment rides the VERSION, not the method: revision 2's deck
         * is not revision 1's, and a library that shared one file between them
         * would let a superseded row's evidence change under it.
         */
        'core/method-library' => ['class' => MethodLibraryEntry::class, 'prefix' => 'est', 'label' => 'Pustaka metode kerja', 'table' => 'core_method_library'],
        'crm/quotations' => ['class' => Quotation::class, 'prefix' => 'crm', 'label' => 'Penawaran', 'table' => 'crm_quotations'],
        /*
         * P7 — the tender dossier. What attaches here is the OWNER'S paper:
         * the dokumen pemilihan PDF, each addendum, the signed BA aanwijzing.
         * The register rows beside them say what arrived and when; the files
         * are what arrived. The TKDN worksheet and the RKK are deliberately
         * NOT attachable — each is composed from rows this ERP holds, and a
         * dropped PDF beside a computed sheet is a second version of the same
         * claim with nothing keeping the two in step.
         */
        'crm/tender-packages' => ['class' => TenderPackage::class, 'prefix' => 'crm', 'label' => 'Paket tender', 'table' => 'crm_tender_packages'],
        'crm/contracts' => ['class' => Contract::class, 'prefix' => 'crm', 'label' => 'Kontrak', 'table' => 'crm_contracts'],
        'crm/guarantees' => ['class' => Guarantee::class, 'prefix' => 'crm', 'label' => 'Jaminan', 'table' => 'crm_guarantees'],
        /*
         * P1-ENG. The drawing FILE rides the drawing SUBMITTAL, not the
         * register row: what the MK stamped is one revision's sheet, and P0-D's
         * dwg/dxf policy applies to exactly that file. The material submittal
         * carries brochures and mill certificates the same way. The IPP is
         * deliberately NOT attachable — its evidence IS the approved
         * submittals its lines reference, and a photo dropped on an IPP would
         * be a claim the gate never checked.
         */
        'engineering/drawing-submittals' => ['class' => DrawingSubmittal::class, 'prefix' => 'eng', 'label' => 'Persetujuan gambar (SDS)', 'table' => 'eng_drawing_submittals'],
        'engineering/material-submittals' => ['class' => MaterialSubmittal::class, 'prefix' => 'eng', 'label' => 'Persetujuan material (SMS)', 'table' => 'eng_material_submittals'],
        /*
         * P1-QC — inspection photos ride the INSPECTION sheet: the photo of the
         * exposed rebar IS the evidence the checklist verdict rests on. The NCR
         * and the concrete sample are deliberately NOT attachable here — an
         * NCR's evidence is the inspection it cites, and a sample's is its
         * computed break sheet, not a dropped photo the pass/fail never saw.
         */
        'quality/inspections' => ['class' => Inspection::class, 'prefix' => 'qc', 'label' => 'Inspeksi mutu (QCI)', 'table' => 'qc_inspections'],
        'estimation/boqs' => ['class' => Boq::class, 'prefix' => 'est', 'label' => 'BOQ / RAB', 'table' => 'est_boqs'],
        'estimation/cost-budgets' => ['class' => CostBudget::class, 'prefix' => 'est', 'label' => 'RAP', 'table' => 'est_cost_budgets'],
        'projects/projects' => ['class' => Project::class, 'prefix' => 'prj', 'label' => 'Proyek', 'table' => 'prj_projects'],
        'projects/daily-reports' => ['class' => DailyReport::class, 'prefix' => 'prj', 'label' => 'Laporan harian', 'table' => 'prj_daily_reports'],
        'projects/bast' => ['class' => Bast::class, 'prefix' => 'prj', 'label' => 'BAST', 'table' => 'prj_bast'],
        /*
         * P3 — the opname's photos. The spec asks for them on the OPNAME, and
         * that is the honest place: a photo of the poured slab with the tape
         * across it IS the evidence the measured volume rests on, and it is
         * what the MK looks at before signing the backsheet. The BAPP zona is
         * deliberately NOT attachable — its evidence is the NCR register the
         * "Selesai" gate reads, not a dropped photo that gate never saw — and
         * neither is the contract-variation register, which is a transcription
         * of a signed addendum BOQ, not a document of its own.
         */
        'projects/progress-measurements' => ['class' => ProgressMeasurement::class, 'prefix' => 'prj', 'label' => 'Opname progres owner (OPN)', 'table' => 'prj_progress_measurements'],
        // A punch list without photos is half a punch list: the photo of the
        // unlevel lift door IS the temuan, and the photo of the repair is what
        // gets it past verification.
        'projects/defects' => ['class' => Defect::class, 'prefix' => 'prj', 'label' => 'Temuan (defect)', 'table' => 'prj_defects'],
        // P0-C, per the spec's parenthetical: foto izin kerja (kondisi area,
        // APD terpasang) on the IKL, foto muatan on the IMK gate pass — the
        // photo of the loaded truck is what the guard's periksa stamp attests
        // to. ILB deliberately not here: an overtime sheet's evidence is its
        // signatures, which live on paper, not in a camera roll.
        'projects/work-permits' => ['class' => WorkPermit::class, 'prefix' => 'prj', 'label' => 'Izin kerja lapangan', 'table' => 'prj_work_permits'],
        'projects/gate-passes' => ['class' => GatePass::class, 'prefix' => 'prj', 'label' => 'Izin masuk/keluar material', 'table' => 'prj_gate_passes'],
        // P6 — temuan panduan §7.7: foto kejadian menempel pada INSIDENNYA,
        // bukan dititipkan ke laporan harian dengan nomor insiden di
        // keterangan. Foto titik jatuh material adalah bukti investigasinya.
        'projects/safety-incidents' => ['class' => SafetyIncident::class, 'prefix' => 'prj', 'label' => 'Insiden K3 (SMK3)', 'table' => 'prj_safety_incidents'],
        'procurement/purchase-requisitions' => ['class' => PurchaseRequisition::class, 'prefix' => 'prc', 'label' => 'Permintaan pembelian', 'table' => 'prc_purchase_requisitions'],
        'procurement/purchase-orders' => ['class' => PurchaseOrder::class, 'prefix' => 'prc', 'label' => 'Pesanan pembelian', 'table' => 'prc_purchase_orders'],
        'procurement/vendors' => ['class' => Vendor::class, 'prefix' => 'prc', 'label' => 'Vendor', 'table' => 'prc_vendors'],
        // Lampiran menempel pada BARIS register — hasil scan SBU/NIB dengan
        // masa berlakunya sendiri — bukan pada vendor secara umum.
        'procurement/vendor-documents' => ['class' => VendorDocument::class, 'prefix' => 'prc', 'label' => 'Dokumen vendor', 'table' => 'prc_vendor_documents'],
        // P2 — the daftar hadir scan rides the negotiation minute (BAN): the
        // signed attendance sheet IS the evidence the minute happened. The award
        // decision is deliberately NOT attachable — its evidence is the approved
        // BAN it cites and the committee that signed it, recorded as fields, not
        // a dropped photo the ladder never checked.
        'procurement/negotiation-minutes' => ['class' => NegotiationMinute::class, 'prefix' => 'prc', 'label' => 'BA Negosiasi (daftar hadir)', 'table' => 'prc_negotiation_minutes'],
        // Lampiran menempel pada BARIS register — hasil scan SBU/NIB dengan
        // masa berlakunya sendiri — bukan pada vendor secara umum.
        'inventory/goods-receipts' => ['class' => GoodsReceipt::class, 'prefix' => 'inv', 'label' => 'Penerimaan barang', 'table' => 'inv_goods_receipts'],
        'inventory/stock-adjustments' => ['class' => StockAdjustment::class, 'prefix' => 'inv', 'label' => 'Penyesuaian stok', 'table' => 'inv_stock_adjustments'],
        'subcontract/subcontracts' => ['class' => Subcontract::class, 'prefix' => 'scm', 'label' => 'SPK subkontraktor', 'table' => 'scm_subcontracts'],
        'subcontract/progress-claims' => ['class' => ProgressClaim::class, 'prefix' => 'scm', 'label' => 'Opname subkon', 'table' => 'scm_progress_claims'],
        'finance/ar-invoices' => ['class' => ArInvoice::class, 'prefix' => 'fin', 'label' => 'Invoice termin', 'table' => 'fin_ar_invoices'],
        'finance/ap-bills' => ['class' => ApBill::class, 'prefix' => 'fin', 'label' => 'Tagihan vendor', 'table' => 'fin_ap_bills'],
        'finance/payments' => ['class' => Payment::class, 'prefix' => 'fin', 'label' => 'Pembayaran', 'table' => 'fin_payments'],
        'finance/journals' => ['class' => Journal::class, 'prefix' => 'fin', 'label' => 'Voucher jurnal', 'table' => 'fin_journals'],
        // Struk bensin dan nota warung adalah BUKTI bon kas kecil — tanpa
        // lampiran, penggantian imprest berjalan di atas kata-kata saja.
        'finance/petty-cash-vouchers' => ['class' => PettyCashVoucher::class, 'prefix' => 'fin', 'label' => 'Bon kas kecil', 'table' => 'fin_petty_cash_vouchers'],
        'finance/kasbon' => ['class' => Kasbon::class, 'prefix' => 'fin', 'label' => 'Kasbon', 'table' => 'fin_kasbons'],
        // Selfie absen masuk/pulang (F-4). Terdaftar di sini BUKAN supaya orang
        // melampirkan berkas ke absensi lewat layar lampiran biasa, melainkan
        // supaya foto yang ditulis AttendanceClockService punya slug — tanpa
        // baris ini Attachment::documentSlug() menjawab null dan selfienya
        // menjadi berkas yang tersimpan tetapi tidak bisa dibuka siapa pun,
        // termasuk pengawas yang justru menjadi alasan foto itu diminta.
        // Izinnya hr: melihat selfie seseorang tidak boleh lebih mudah
        // daripada melihat baris absensinya.
        'hr/attendances' => ['class' => Attendance::class, 'prefix' => 'hr', 'label' => 'Absensi harian', 'table' => 'hr_attendances'],
        'hr/employees' => ['class' => Employee::class, 'prefix' => 'hr', 'label' => 'Karyawan', 'table' => 'hr_employees'],
        'hr/certificates' => ['class' => Certificate::class, 'prefix' => 'hr', 'label' => 'Sertifikat', 'table' => 'hr_certificates'],
        // Surat dokter untuk sakit, undangan/akta untuk cuti khusus — bukti
        // yang dibaca penyetuju SEBELUM menyetujui absennya, bukan sesudah.
        'hr/leave-requests' => ['class' => LeaveRequest::class, 'prefix' => 'hr', 'label' => 'Pengajuan cuti', 'table' => 'hr_leave_requests'],
        'servicedesk/tickets' => ['class' => Ticket::class, 'prefix' => 'svc', 'label' => 'Tiket layanan', 'table' => 'svc_tickets'],
        'servicedesk/field-reports' => ['class' => FieldReport::class, 'prefix' => 'svc', 'label' => 'Laporan lapangan', 'table' => 'svc_field_reports'],
        'assets/assets' => ['class' => Asset::class, 'prefix' => 'ast', 'label' => 'Aset', 'table' => 'ast_assets'],
    ];

    public static function slugs(): array
    {
        return array_keys(self::MAP);
    }

    public static function has(string $slug): bool
    {
        return isset(self::MAP[$slug]);
    }

    /** @return class-string|null */
    public static function classFor(string $slug): ?string
    {
        return self::MAP[$slug]['class'] ?? null;
    }

    public static function prefixFor(string $slug): ?string
    {
        return self::MAP[$slug]['prefix'] ?? null;
    }

    public static function labelFor(string $slug): string
    {
        return self::MAP[$slug]['label'] ?? 'Dokumen';
    }

    /**
     * Every attachable document grouped by the permission prefix it answers to.
     *
     * The expiry watcher needs exactly this shape: one alarm group per module,
     * because a finding carries ONE permission and one title, and the person
     * who must renew an expiring insurance policy on an SPK is not the person
     * who must renew a calibration certificate on an inspection sheet.
     *
     * @return array<string, array<string, array{class: class-string, prefix: string, label: string, table: string}>>
     */
    public static function byPrefix(): array
    {
        $grouped = [];

        foreach (self::MAP as $slug => $entry) {
            $grouped[$entry['prefix']][$slug] = $entry;
        }

        ksort($grouped);

        return $grouped;
    }

    /** The reverse direction, for rendering an attachment's parent. */
    public static function slugForClass(string $class): ?string
    {
        foreach (self::MAP as $slug => $entry) {
            if ($entry['class'] === $class) {
                return $slug;
            }
        }

        return null;
    }

    /** @return array<string, array{class: class-string, prefix: string, label: string}> */
    public static function all(): array
    {
        return self::MAP;
    }
}
