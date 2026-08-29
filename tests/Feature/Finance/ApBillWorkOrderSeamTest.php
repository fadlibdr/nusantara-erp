<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\ApBillService;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\WorkOrder;
use Modules\Procurement\Models\WorkOrderBilling;
use Modules\Procurement\Services\WorkOrderBillingService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P5 — seam tagihan AP atas satu periode PPK:
 * fin_ap_bills.work_order_billing_id (migrasi 001127, cermin labor_claim_id).
 */
class ApBillWorkOrderSeamTest extends ErpTestCase
{
    private User $admin;

    private Project $project;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger();

        $this->admin = $this->adminUser();
        Sanctum::actingAs($this->admin);

        $this->project = Project::create(['name' => 'Gedung Kantor', 'type' => 'construction']);
        $this->vendor = Vendor::create([
            'name' => 'PT Alat Berat Nusantara',
            'classification' => 'jasa',
            'vendor_type' => 'rental',
            'is_pkp' => false,
            'status' => 'active',
        ]);
    }

    private function approvedBilling(): WorkOrderBilling
    {
        /** @var WorkOrder $workOrder */
        $workOrder = WorkOrder::create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'title' => 'Sewa scaffolding lengkap',
            'value' => 0,
            'ppn_rate' => 0,
            'status' => DocumentStatus::Draft,
        ]);
        $workOrder->items()->create([
            'line_no' => 1,
            'description' => 'Sewa scaffolding lengkap',
            'rate_basis' => 'per_bulan',
            'rate' => 15_000_000,
            'qty_periods' => 6,
            'amount' => 90_000_000,
        ]);
        $workOrder->forceFill(['value' => 90_000_000, 'status' => DocumentStatus::Approved])->save();

        return app(WorkOrderBillingService::class)->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);
    }

    public function test_tagihan_ap_dari_billing_ppk_membawa_dpp_dan_tautannya(): void
    {
        $billing = $this->approvedBilling();

        $response = $this->postJson('/api/finance/ap-bills', [
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-070',
        ])->assertCreated();

        $bill = ApBill::query()->findOrFail($response->json('data.id'));

        $this->assertSame($billing->id, (int) $bill->work_order_billing_id);
        $this->assertSame($this->vendor->id, (int) $bill->vendor_id);
        $this->assertSame($this->project->id, (int) $bill->project_id);
        $this->assertSame('15000000.00', (string) $bill->dpp);
        // Non-PKP: tanpa PPN; tanpa PPh kecuali dinyatakan pemanggil.
        $this->assertSame('0.00', (string) $bill->ppn_amount);
        $this->assertStringContainsString($billing->code, (string) $bill->description);
    }

    public function test_satu_billing_hanya_bisa_ditagihkan_sekali_selama_tagihannya_hidup(): void
    {
        $billing = $this->approvedBilling();

        $this->postJson('/api/finance/ap-bills', [
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-070',
        ])->assertCreated();

        $response = $this->postJson('/api/finance/ap-bills', [
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-071',
        ])->assertUnprocessable();

        $this->assertStringContainsString('sudah ada', (string) $response->json('message'));

        // Tagihan yang DIBATALKAN melepaskan billing-nya: pembatalan membalik
        // jurnal, jadi menagih ulang bukan tagih ganda.
        ApBill::query()->first()->forceFill(['status' => DocumentStatus::Cancelled])->save();

        $this->postJson('/api/finance/ap-bills', [
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-072',
        ])->assertCreated();
    }

    public function test_dpp_tagihan_billing_ppk_tidak_bisa_diketik_ulang(): void
    {
        $billing = $this->approvedBilling();

        $bill = app(ApBillService::class)->create([
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-070',
        ]);

        // DPP-nya turunan register/kalender; angka ketikan memutus rantai
        // "satu periode = satu kali rupiahnya". Perbaikannya batalkan dan
        // terbitkan ulang — sikap yang sama dengan tagihan parsial.
        $response = $this->putJson("/api/finance/ap-bills/{$bill->id}", [
            'dpp' => 99_000_000,
            'vendor_invoice_no' => 'INV-ABN-070',
        ])->assertUnprocessable();

        $this->assertStringContainsString('diturunkan', (string) $response->json('message'));
        $this->assertSame('15000000.00', (string) $bill->fresh()->dpp);
    }

    public function test_persetujuan_tagihan_membukukan_biaya_proyek_kategori_alat(): void
    {
        $billing = $this->approvedBilling();

        $bill = app(ApBillService::class)->create([
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-070',
            'bill_date' => '2026-07-31',
        ]);

        $bill->submit($this->admin);

        $approver = User::query()->create([
            'name' => 'Manajer Keuangan',
            'email' => 'fm-p5@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);

        app(ApBillService::class)->approve($bill, $approver);

        $cost = ProjectCost::query()
            ->where('project_id', $this->project->id)
            ->where('reference_type', 'ap_bill')
            ->where('reference_id', $bill->id)
            ->first();

        $this->assertNotNull($cost, 'Tagihan PPK yang disetujui harus tercatat sebagai biaya proyek.');
        $this->assertSame('equipment', $cost->cost_category instanceof \BackedEnum ? $cost->cost_category->value : (string) $cost->cost_category);
        $this->assertSame('15000000.00', (string) $cost->amount);
    }
}
