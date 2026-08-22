<?php

namespace Tests\Unit\Crm;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Enums\ScopeType;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Tests\ErpTestCase;

/**
 * Winning a quotation opens a draft contract that carries the commercial data
 * over. The contract value is the quotation DPP (excl. PPN), never the total.
 */
class ContractFromQuotationTest extends ErpTestCase
{
    use CrmFixtures;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->makeCustomer();
    }

    /**
     * Subtotal 25.000.000 - diskon 2.500.000 = DPP 22.500.000,
     * PPN 11% = 2.475.000, total 24.975.000.
     */
    private function wonQuotation(array $data = []): array
    {
        $quotation = $this->quotations()->create(array_merge([
            'customer_id' => $this->customer->id,
            'title' => 'Instalasi ELV & ICT 12 cabang',
            'scope_type' => 'system_integration',
            'discount_amount' => 2500000,
            'items' => [
                ['description' => 'Kabel UTP Cat6', 'qty' => 10, 'unit' => 'roll', 'unit_price' => 1500000],
                ['description' => 'CCTV Dome 4MP', 'qty' => 2.5, 'unit' => 'unit', 'unit_price' => 4000000],
            ],
        ], $data));

        $quotation->submit();
        $quotation->approve($this->makeUser());

        return [$quotation, $this->quotations()->markWon($quotation)];
    }

    public function test_the_contract_carries_over_the_customer_and_the_scope(): void
    {
        [$quotation, $contract] = $this->wonQuotation();

        $this->assertSame($this->customer->id, (int) $contract->customer_id);
        $this->assertSame($quotation->id, (int) $contract->quotation_id);
        $this->assertSame('Instalasi ELV & ICT 12 cabang', $contract->title);
        $this->assertSame(ScopeType::SystemIntegration, $contract->scope_type);
    }

    public function test_the_contract_value_is_the_quotation_dpp_not_the_total(): void
    {
        [$quotation, $contract] = $this->wonQuotation();

        // DPP 22.500.000 (bukan total 24.975.000 yang sudah termasuk PPN)
        $this->assertSame(22500000.0, (float) $quotation->dpp);
        $this->assertSame(22500000.0, (float) $contract->value);
    }

    public function test_the_contract_carries_over_the_ppn_rate_amount_and_total(): void
    {
        [, $contract] = $this->wonQuotation();

        // 22.500.000 * 11 / 100 = 2.475.000 ; total 24.975.000
        $this->assertSame(11.0, (float) $contract->ppn_rate);
        $this->assertSame(2475000.0, (float) $contract->ppn_amount);
        $this->assertSame(24975000.0, (float) $contract->total_with_ppn);
    }

    public function test_a_non_standard_quotation_ppn_rate_is_carried_not_re_derived(): void
    {
        [, $contract] = $this->wonQuotation(['ppn_rate' => 12]);

        // 22.500.000 * 12 / 100 = 2.700.000 ; total 25.200.000
        $this->assertSame(12.0, (float) $contract->ppn_rate);
        $this->assertSame(2700000.0, (float) $contract->ppn_amount);
        $this->assertSame(25200000.0, (float) $contract->total_with_ppn);
    }

    public function test_the_contract_retention_defaults_from_the_settings_layer(): void
    {
        $this->setSetting('projects.default_retention_pct', 7.5);

        [, $contract] = $this->wonQuotation();

        $this->assertSame(7.5, (float) $contract->retention_pct);
        // 22.500.000 * 7,5 / 100 = 1.687.500
        $this->assertSame(1687500.0, $contract->retentionAmount());
    }

    public function test_the_contract_opens_as_a_numbered_draft(): void
    {
        [, $contract] = $this->wonQuotation();

        $this->assertSame(DocumentStatus::Draft, $contract->status);
        $this->assertStringStartsWith('CTR/', $contract->code);
    }

    public function test_the_generated_contract_has_no_termin_schedule_yet(): void
    {
        [, $contract] = $this->wonQuotation();

        $this->assertSame(0, $contract->termins()->count());

        // ...so it cannot be activated until the schedule is entered.
        try {
            $this->contracts()->activate($contract);
            $this->fail('Expected LogicException when activating a scheduleless contract.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('has no termin schedule', $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Draft, Contract::query()->findOrFail($contract->id)->status);
    }

    public function test_a_quotation_with_zero_dpp_still_produces_a_zero_value_contract(): void
    {
        [, $contract] = $this->wonQuotation(['items' => [], 'discount_amount' => 0]);

        $this->assertSame(0.0, (float) $contract->value);
        $this->assertSame(0.0, (float) $contract->ppn_amount);
        $this->assertSame(0.0, (float) $contract->total_with_ppn);
    }

    public function test_the_quotation_is_linked_back_from_the_contract(): void
    {
        [$quotation, $contract] = $this->wonQuotation();

        $this->assertSame($contract->id, Quotation::query()->findOrFail($quotation->id)->contract->id);
    }
}
