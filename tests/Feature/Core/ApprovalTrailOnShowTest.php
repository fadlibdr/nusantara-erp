<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Carbon;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Location;
use Modules\Core\Traits\Approvable;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Estimation\Models\Boq;
use Modules\Finance\Models\ApBill;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Enums\BastType;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Subcontract\Models\Subcontract;
use Tests\ErpTestCase;

/**
 * One document per module that gained the approval trail on show() in T3.3.
 *
 * Measured 4 Sep 2026 (HASIL-UJI §6 P-4): 5 of 28 Approvable show() methods
 * loaded `approvals`; the other detail pages had no "Riwayat Persetujuan" card
 * and a status strip without the approver's name and date. Procurement is
 * pinned in PurchaseRequisitionApprovalTrailTest; this file walks the other
 * eight modules through the same door so a future controller that drops the
 * relation again fails here, in its own module's name.
 *
 * Each case writes a `submitted` row directly (the trait's own shape) rather
 * than driving the module's submit gate — the gate is that module's business
 * and has its own suite; what is asserted here is only that show() carries
 * the row back in the PaymentResource shape.
 */
class ApprovalTrailOnShowTest extends ErpTestCase
{
    private const SUBMITTED_AT = '2026-09-04 09:15:00';

    public function test_crm_quotation_show_carries_the_trail(): void
    {
        $quotation = Quotation::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Penawaran Upgrade CCTV Gudang',
            'scope_type' => 'system_integration',
            'total' => 33_970_000,
            'status' => 'draft',
        ]);

        $this->assertTrailOnShow($quotation, "/api/crm/quotations/{$quotation->id}");
    }

    public function test_engineering_ipp_show_carries_the_trail(): void
    {
        $ipp = WorkPermitIpp::query()->create([
            'project_id' => $this->project()->id,
            'scope' => 'struktur',
            'description' => 'Pengecoran pondasi bore pile Zona A',
            'planned_start' => '2026-09-10',
            'duration_days' => 14,
            'status' => DocumentStatus::Draft,
        ]);

        $this->assertTrailOnShow($ipp, "/api/engineering/ipp/{$ipp->id}");
    }

    public function test_estimation_boq_show_carries_the_trail(): void
    {
        $boq = Boq::query()->create([
            'code' => 'BOQ/2026/9001',
            'title' => 'RAB Gedung Kantor',
            'status' => 'draft',
        ]);

        $this->assertTrailOnShow($boq, "/api/estimation/boqs/{$boq->id}");
    }

    public function test_finance_ap_bill_show_carries_the_trail(): void
    {
        $bill = ApBill::query()->create([
            'vendor_id' => $this->vendor()->id,
            'bill_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'description' => 'Tagihan vendor',
            'vendor_invoice_no' => 'INV-2026-0001',
            'dpp' => 100_000_000,
            'total_payable' => 100_000_000,
            'status' => DocumentStatus::Draft,
        ]);

        $this->assertTrailOnShow($bill, "/api/finance/ap-bills/{$bill->id}");
    }

    public function test_hr_payroll_run_show_carries_the_trail(): void
    {
        $run = PayrollRun::query()->create([
            'code' => 'PYR/TEST/001',
            'period_year' => 2026,
            'period_month' => 8,
            'run_type' => PayrollRunType::Regular,
            'payment_date' => '2026-08-25',
            'status' => DocumentStatus::Draft,
        ]);

        $this->assertTrailOnShow($run, "/api/hr/payroll-runs/{$run->id}");
    }

    public function test_projects_bast_show_carries_the_trail(): void
    {
        $bast = Bast::query()->create([
            'code' => 'BAST/2026/VII/0001',
            'project_id' => $this->project()->id,
            'bast_type' => BastType::Bast1,
            'handover_date' => '2026-07-15',
            'customer_representative' => 'Ir. Hendra Kusuma',
            'retention_release_due' => '2027-07-15',
            'status' => DocumentStatus::Draft,
        ]);

        $this->assertTrailOnShow($bast, "/api/projects/bast/{$bast->id}");
    }

    public function test_quality_inspection_show_carries_the_trail(): void
    {
        $project = $this->project();
        $inspection = Inspection::query()->create([
            'project_id' => $project->id,
            'location_id' => Location::query()->create([
                'project_id' => $project->id,
                'kind' => 'floor',
                'code' => 'LT-001',
                'name' => 'Lantai 1 Zona A',
                'sort_order' => 1,
            ])->id,
            'template_id' => InspectionTemplate::query()->create([
                'code' => 'Q01',
                'work_package' => 'Pengecoran kolom struktur',
                'stage' => InspectionStage::Before,
            ])->id,
            'inspected_at' => '2026-09-03',
            'passed' => true,
            'status' => DocumentStatus::Draft,
        ]);

        $this->assertTrailOnShow($inspection, "/api/quality/inspections/{$inspection->id}");
    }

    public function test_subcontract_spk_show_carries_the_trail(): void
    {
        $spk = Subcontract::query()->create([
            'vendor_id' => $this->vendor(['is_subcontractor' => true, 'classification' => 'sipil'])->id,
            'project_id' => $this->project()->id,
            'title' => 'Pekerjaan struktur baja',
            'value' => 150_000_000,
            'ppn_rate' => 0,
            'retention_pct' => 5,
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'pph_rate' => 2.65,
            'status' => DocumentStatus::Draft,
        ]);

        $this->assertTrailOnShow($spk, "/api/subcontract/subcontracts/{$spk->id}");
    }

    // -------------------------------------------------------------- helpers

    /** @param object&Approvable $document */
    private function assertTrailOnShow(object $document, string $url): void
    {
        $admin = $this->adminUser();

        Carbon::setTestNow(self::SUBMITTED_AT);
        $document->approvals()->create([
            'action' => 'submitted',
            'user_id' => $admin->id,
            'note' => 'Diajukan lewat uji',
        ]);
        Carbon::setTestNow();

        $response = $this->actingAs($admin)->getJson($url);

        $response->assertOk()
            ->assertJsonCount(1, 'data.approvals')
            ->assertJsonPath('data.approvals.0.action', 'submitted')
            ->assertJsonPath('data.approvals.0.note', 'Diajukan lewat uji')
            ->assertJsonPath('data.approvals.0.created_at', '2026-09-04T09:15:00+07:00')
            ->assertJsonPath('data.approvals.0.user.id', $admin->id)
            ->assertJsonPath('data.approvals.0.user.name', $admin->name);

        $this->assertSame(
            ['id', 'action', 'note', 'created_at', 'user'],
            array_keys($response->json('data.approvals.0')),
            "{$url} does not answer the PaymentResource shape",
        );
    }

    private function project(): Project
    {
        return Project::query()->create([
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    private function vendor(array $overrides = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'name' => 'PT Semen Distribusi Utama',
            'classification' => 'material',
            'is_pkp' => true,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ], $overrides));
    }
}
