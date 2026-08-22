<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\QuotationService;
use Tests\ErpTestCase;

/**
 * The settings screen is only worth anything if an override actually reaches
 * the documents. These tests go the whole way: PUT /api/core/settings, then
 * create a real document through its service and check the arithmetic.
 */
class SettingOverrideEndToEndTest extends ErpTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminUser();
        $this->actingAs($this->admin, 'sanctum');
        Carbon::setTestNow('2026-07-15 09:00:00');
    }

    private function customer(): Customer
    {
        return Customer::query()->create(['name' => 'PT Bangun Sejahtera']);
    }

    private function quotationService(): QuotationService
    {
        return app(QuotationService::class);
    }

    /**
     * A second pair of eyes. Whoever submits a document may not approve it, so
     * a one-user lifecycle is no longer a lifecycle a real operator could walk.
     */
    private function secondApprover(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'direktur@test.local'],
            ['name' => 'Budi Santoso', 'password' => 'password', 'is_active' => true],
        );
    }

    /**
     * One line of 10 × Rp 1.000.000 = Rp 10.000.000 DPP.
     */
    private function createQuotation(string $title = 'Penawaran ELV'): Quotation
    {
        return $this->quotationService()->create([
            'customer_id' => $this->customer()->id,
            'title' => $title,
            'scope_type' => 'system_integration',
            'items' => [
                ['description' => 'Instalasi CCTV', 'qty' => 10, 'unit' => 'titik', 'unit_price' => 1_000_000],
            ],
        ]);
    }

    public function test_a_new_quotation_uses_the_shipped_ppn_rate(): void
    {
        $quotation = $this->createQuotation();

        // DPP 10.000.000 × 11% = 1.100.000; total 11.100.000
        $this->assertSame(11.0, (float) $quotation->ppn_rate);
        $this->assertSame(10_000_000.0, (float) $quotation->dpp);
        $this->assertSame(1_100_000.0, (float) $quotation->ppn_amount);
        $this->assertSame(11_100_000.0, (float) $quotation->total);
    }

    public function test_raising_the_ppn_rate_through_the_endpoint_changes_the_next_quotation(): void
    {
        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertOk();

        $quotation = $this->createQuotation();

        // DPP 10.000.000 × 12% = 1.200.000; total 11.200.000
        $this->assertSame(12.0, (float) $quotation->ppn_rate);
        $this->assertSame(1_200_000.0, (float) $quotation->ppn_amount);
        $this->assertSame(11_200_000.0, (float) $quotation->total);
    }

    public function test_an_existing_quotation_keeps_the_rate_it_was_created_with(): void
    {
        $before = $this->createQuotation('Penawaran lama');

        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertOk();

        $after = $this->createQuotation('Penawaran baru');

        // The old document snapshots 11%, the new one picks up 12%.
        $this->assertSame(11.0, (float) $before->fresh()->ppn_rate);
        $this->assertSame(1_100_000.0, (float) $before->fresh()->ppn_amount);
        $this->assertSame(12.0, (float) $after->ppn_rate);
    }

    public function test_resetting_the_rate_restores_the_shipped_default_for_later_documents(): void
    {
        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => 12]])->assertOk();
        $this->assertSame(12.0, (float) $this->createQuotation('Dengan 12%')->ppn_rate);

        $this->putJson('/api/core/settings', ['settings' => ['tax.ppn_rate' => null]])->assertOk();

        $this->assertSame(11.0, (float) $this->createQuotation('Kembali ke 11%')->ppn_rate);
    }

    public function test_the_retention_override_reaches_the_contract_opened_from_a_won_quotation(): void
    {
        $this->putJson('/api/core/settings', ['settings' => ['projects.default_retention_pct' => 7.5]])->assertOk();

        $quotation = $this->createQuotation();
        // Two people, because maker-checker refuses the submitter's own
        // approval — which is also how a quotation is really signed off.
        $quotation->submit($this->admin);
        $quotation->approve($this->secondApprover());

        $contract = $this->quotationService()->markWon($quotation->fresh());

        $this->assertSame(7.5, (float) $contract->retention_pct);
        // Contract value is the DPP, PPN carried over from the quotation.
        $this->assertSame(10_000_000.0, (float) $contract->value);
        $this->assertSame(11.0, (float) $contract->ppn_rate);
    }

    public function test_the_document_format_override_reaches_the_quotation_code(): void
    {
        $this->assertSame('QTN/2026/VII/0001', $this->createQuotation('Format bawaan')->code);

        $this->putJson('/api/core/settings', ['settings' => ['documents.QTN' => 'PNW-{Y}-{N5}']])->assertOk();

        // Same counter, new shape: the second quotation of 2026 is number 2.
        $this->assertSame('PNW-2026-00002', $this->createQuotation('Format baru')->code);
    }
}
