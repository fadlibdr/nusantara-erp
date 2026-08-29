<?php

namespace Modules\Core\Support;

use Modules\Assets\Models\Asset;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Guarantee;
use Modules\Crm\Models\Quotation;
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
use Modules\Projects\Models\Project;
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
 */
class AttachableDocuments
{
    /** @var array<string, array{class: class-string, prefix: string, label: string}> */
    private const MAP = [
        'crm/quotations' => ['class' => Quotation::class, 'prefix' => 'crm', 'label' => 'Penawaran'],
        'crm/contracts' => ['class' => Contract::class, 'prefix' => 'crm', 'label' => 'Kontrak'],
        'crm/guarantees' => ['class' => Guarantee::class, 'prefix' => 'crm', 'label' => 'Jaminan'],
        /*
         * P1-ENG. The drawing FILE rides the drawing SUBMITTAL, not the
         * register row: what the MK stamped is one revision's sheet, and P0-D's
         * dwg/dxf policy applies to exactly that file. The material submittal
         * carries brochures and mill certificates the same way. The IPP is
         * deliberately NOT attachable — its evidence IS the approved
         * submittals its lines reference, and a photo dropped on an IPP would
         * be a claim the gate never checked.
         */
        'engineering/drawing-submittals' => ['class' => DrawingSubmittal::class, 'prefix' => 'eng', 'label' => 'Persetujuan gambar (SDS)'],
        'engineering/material-submittals' => ['class' => MaterialSubmittal::class, 'prefix' => 'eng', 'label' => 'Persetujuan material (SMS)'],
        /*
         * P1-QC — inspection photos ride the INSPECTION sheet: the photo of the
         * exposed rebar IS the evidence the checklist verdict rests on. The NCR
         * and the concrete sample are deliberately NOT attachable here — an
         * NCR's evidence is the inspection it cites, and a sample's is its
         * computed break sheet, not a dropped photo the pass/fail never saw.
         */
        'quality/inspections' => ['class' => Inspection::class, 'prefix' => 'qc', 'label' => 'Inspeksi mutu (QCI)'],
        'estimation/boqs' => ['class' => Boq::class, 'prefix' => 'est', 'label' => 'BOQ / RAB'],
        'estimation/cost-budgets' => ['class' => CostBudget::class, 'prefix' => 'est', 'label' => 'RAP'],
        'projects/projects' => ['class' => Project::class, 'prefix' => 'prj', 'label' => 'Proyek'],
        'projects/daily-reports' => ['class' => DailyReport::class, 'prefix' => 'prj', 'label' => 'Laporan harian'],
        'projects/bast' => ['class' => Bast::class, 'prefix' => 'prj', 'label' => 'BAST'],
        // A punch list without photos is half a punch list: the photo of the
        // unlevel lift door IS the temuan, and the photo of the repair is what
        // gets it past verification.
        'projects/defects' => ['class' => Defect::class, 'prefix' => 'prj', 'label' => 'Temuan (defect)'],
        // P0-C, per the spec's parenthetical: foto izin kerja (kondisi area,
        // APD terpasang) on the IKL, foto muatan on the IMK gate pass — the
        // photo of the loaded truck is what the guard's periksa stamp attests
        // to. ILB deliberately not here: an overtime sheet's evidence is its
        // signatures, which live on paper, not in a camera roll.
        'projects/work-permits' => ['class' => WorkPermit::class, 'prefix' => 'prj', 'label' => 'Izin kerja lapangan'],
        'projects/gate-passes' => ['class' => GatePass::class, 'prefix' => 'prj', 'label' => 'Izin masuk/keluar material'],
        'procurement/purchase-requisitions' => ['class' => PurchaseRequisition::class, 'prefix' => 'prc', 'label' => 'Permintaan pembelian'],
        'procurement/purchase-orders' => ['class' => PurchaseOrder::class, 'prefix' => 'prc', 'label' => 'Pesanan pembelian'],
        'procurement/vendors' => ['class' => Vendor::class, 'prefix' => 'prc', 'label' => 'Vendor'],
        // Lampiran menempel pada BARIS register — hasil scan SBU/NIB dengan
        // masa berlakunya sendiri — bukan pada vendor secara umum.
        'procurement/vendor-documents' => ['class' => VendorDocument::class, 'prefix' => 'prc', 'label' => 'Dokumen vendor'],
        // P2 — the daftar hadir scan rides the negotiation minute (BAN): the
        // signed attendance sheet IS the evidence the minute happened. The award
        // decision is deliberately NOT attachable — its evidence is the approved
        // BAN it cites and the committee that signed it, recorded as fields, not
        // a dropped photo the ladder never checked.
        'procurement/negotiation-minutes' => ['class' => NegotiationMinute::class, 'prefix' => 'prc', 'label' => 'BA Negosiasi (daftar hadir)'],
        // Lampiran menempel pada BARIS register — hasil scan SBU/NIB dengan
        // masa berlakunya sendiri — bukan pada vendor secara umum.
        'inventory/goods-receipts' => ['class' => GoodsReceipt::class, 'prefix' => 'inv', 'label' => 'Penerimaan barang'],
        'inventory/stock-adjustments' => ['class' => StockAdjustment::class, 'prefix' => 'inv', 'label' => 'Penyesuaian stok'],
        'subcontract/subcontracts' => ['class' => Subcontract::class, 'prefix' => 'scm', 'label' => 'SPK subkontraktor'],
        'subcontract/progress-claims' => ['class' => ProgressClaim::class, 'prefix' => 'scm', 'label' => 'Opname subkon'],
        'finance/ar-invoices' => ['class' => ArInvoice::class, 'prefix' => 'fin', 'label' => 'Invoice termin'],
        'finance/ap-bills' => ['class' => ApBill::class, 'prefix' => 'fin', 'label' => 'Tagihan vendor'],
        'finance/payments' => ['class' => Payment::class, 'prefix' => 'fin', 'label' => 'Pembayaran'],
        'finance/journals' => ['class' => Journal::class, 'prefix' => 'fin', 'label' => 'Voucher jurnal'],
        // Struk bensin dan nota warung adalah BUKTI bon kas kecil — tanpa
        // lampiran, penggantian imprest berjalan di atas kata-kata saja.
        'finance/petty-cash-vouchers' => ['class' => PettyCashVoucher::class, 'prefix' => 'fin', 'label' => 'Bon kas kecil'],
        'finance/kasbon' => ['class' => Kasbon::class, 'prefix' => 'fin', 'label' => 'Kasbon'],
        'hr/employees' => ['class' => Employee::class, 'prefix' => 'hr', 'label' => 'Karyawan'],
        'hr/certificates' => ['class' => Certificate::class, 'prefix' => 'hr', 'label' => 'Sertifikat'],
        // Surat dokter untuk sakit, undangan/akta untuk cuti khusus — bukti
        // yang dibaca penyetuju SEBELUM menyetujui absennya, bukan sesudah.
        'hr/leave-requests' => ['class' => LeaveRequest::class, 'prefix' => 'hr', 'label' => 'Pengajuan cuti'],
        'servicedesk/tickets' => ['class' => Ticket::class, 'prefix' => 'svc', 'label' => 'Tiket layanan'],
        'servicedesk/field-reports' => ['class' => FieldReport::class, 'prefix' => 'svc', 'label' => 'Laporan lapangan'],
        'assets/assets' => ['class' => Asset::class, 'prefix' => 'ast', 'label' => 'Aset'],
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
