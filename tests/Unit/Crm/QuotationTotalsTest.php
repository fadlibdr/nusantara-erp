<?php

namespace Tests\Unit\Crm;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Tests\ErpTestCase;

/**
 * Quotation (penawaran) arithmetic:
 *
 *   line amount = qty * unit_price
 *   subtotal    = sum(line amounts)
 *   dpp         = subtotal - discount
 *   ppn         = dpp * ppn_rate / 100
 *   total       = dpp + ppn
 *
 * The default PPN rate comes from the settings layer (erp.tax.ppn_rate).
 */
class QuotationTotalsTest extends ErpTestCase
{
    use CrmFixtures;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->makeCustomer();
    }

    private function makeQuotation(array $data = []): Quotation
    {
        return $this->quotations()->create(array_merge([
            'customer_id' => $this->customer->id,
            'title' => 'Penawaran instalasi ELV Gedung A',
            'scope_type' => 'system_integration',
            'valid_until' => '2026-09-30',
            'items' => [
                ['description' => 'Kabel UTP Cat6', 'qty' => 10, 'unit' => 'roll', 'unit_price' => 1500000],
                ['description' => 'CCTV Dome 4MP', 'qty' => 2.5, 'unit' => 'unit', 'unit_price' => 4000000],
            ],
        ], $data));
    }

    public function test_line_amount_is_qty_times_unit_price(): void
    {
        $quotation = $this->makeQuotation();

        $lines = $quotation->items()->orderBy('line_no')->get();

        // 10 x 1.500.000 = 15.000.000 ; 2,5 x 4.000.000 = 10.000.000
        $this->assertSame(15000000.0, (float) $lines[0]->amount);
        $this->assertSame(10000000.0, (float) $lines[1]->amount);
        $this->assertSame(1, (int) $lines[0]->line_no);
        $this->assertSame(2, (int) $lines[1]->line_no);
    }

    public function test_subtotal_is_the_sum_of_the_line_amounts(): void
    {
        $quotation = $this->makeQuotation();

        // 15.000.000 + 10.000.000 = 25.000.000
        $this->assertSame(25000000.0, (float) $quotation->subtotal);
    }

    public function test_dpp_is_subtotal_minus_discount(): void
    {
        $quotation = $this->makeQuotation(['discount_amount' => 2500000]);

        // 25.000.000 - 2.500.000 = 22.500.000
        $this->assertSame(22500000.0, (float) $quotation->dpp);
    }

    public function test_ppn_is_eleven_percent_of_dpp_and_total_is_dpp_plus_ppn(): void
    {
        $quotation = $this->makeQuotation(['discount_amount' => 2500000]);

        // 22.500.000 * 11 / 100 = 2.475.000 ; 22.500.000 + 2.475.000 = 24.975.000
        $this->assertSame(11.0, (float) $quotation->ppn_rate);
        $this->assertSame(2475000.0, (float) $quotation->ppn_amount);
        $this->assertSame(24975000.0, (float) $quotation->total);
    }

    public function test_default_ppn_rate_comes_from_the_settings_layer(): void
    {
        $this->setSetting('tax.ppn_rate', 12.0);

        $quotation = $this->makeQuotation();

        // DPP 25.000.000 * 12 / 100 = 3.000.000 ; total = 28.000.000
        $this->assertSame(12.0, (float) $quotation->ppn_rate);
        $this->assertSame(3000000.0, (float) $quotation->ppn_amount);
        $this->assertSame(28000000.0, (float) $quotation->total);
    }

    public function test_an_explicit_ppn_rate_beats_the_settings_default(): void
    {
        $this->setSetting('tax.ppn_rate', 12.0);

        // Export scope: PPN 0%. The quotation carries its own rate.
        $quotation = $this->makeQuotation(['ppn_rate' => 0]);

        $this->assertSame(0.0, (float) $quotation->ppn_rate);
        $this->assertSame(0.0, (float) $quotation->ppn_amount);
        $this->assertSame(25000000.0, (float) $quotation->total);
    }

    public function test_ppn_is_rounded_to_two_decimals(): void
    {
        $quotation = $this->makeQuotation([
            'items' => [
                ['description' => 'Jasa desain', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1000000.05],
            ],
        ]);

        // 1.000.000,05 * 11 / 100 = 110.000,0055 -> dibulatkan 110.000,01
        $this->assertSame(1000000.05, (float) $quotation->dpp);
        $this->assertSame(110000.01, (float) $quotation->ppn_amount);
        // 1.000.000,05 + 110.000,01 = 1.110.000,06
        $this->assertSame(1110000.06, (float) $quotation->total);
    }

    public function test_a_discount_larger_than_the_subtotal_is_clamped_to_the_subtotal(): void
    {
        $quotation = $this->makeQuotation(['discount_amount' => 99000000]);

        // Diskon tidak boleh membuat DPP negatif: dipotong ke 25.000.000.
        $this->assertSame(25000000.0, (float) $quotation->discount_amount);
        $this->assertSame(0.0, (float) $quotation->dpp);
        $this->assertSame(0.0, (float) $quotation->ppn_amount);
        $this->assertSame(0.0, (float) $quotation->total);
    }

    public function test_a_quotation_without_lines_totals_zero(): void
    {
        $quotation = $this->makeQuotation(['items' => []]);

        $this->assertSame(0, $quotation->items()->count());
        $this->assertSame(0.0, (float) $quotation->subtotal);
        $this->assertSame(0.0, (float) $quotation->dpp);
        $this->assertSame(0.0, (float) $quotation->total);
    }

    public function test_updating_the_lines_replaces_them_wholesale_and_recalculates(): void
    {
        $quotation = $this->makeQuotation();

        $this->quotations()->update($quotation, [
            'items' => [
                ['description' => 'Switch Managed 24 Port', 'qty' => 4, 'unit' => 'unit', 'unit_price' => 7250000],
            ],
        ]);

        $quotation->refresh();

        // Baris lama dihapus seluruhnya: 4 x 7.250.000 = 29.000.000
        $this->assertSame(1, $quotation->items()->count());
        $this->assertSame(1, (int) $quotation->items()->first()->line_no);
        $this->assertSame(29000000.0, (float) $quotation->subtotal);
        $this->assertSame(29000000.0, (float) $quotation->dpp);
        // 29.000.000 * 11 / 100 = 3.190.000 ; total = 32.190.000
        $this->assertSame(3190000.0, (float) $quotation->ppn_amount);
        $this->assertSame(32190000.0, (float) $quotation->total);
    }

    public function test_updating_only_the_header_keeps_the_lines_and_recalculates(): void
    {
        $quotation = $this->makeQuotation();

        $this->quotations()->update($quotation, ['discount_amount' => 5000000]);

        $quotation->refresh();

        $this->assertSame(2, $quotation->items()->count());
        // 25.000.000 - 5.000.000 = 20.000.000 ; PPN 2.200.000 ; total 22.200.000
        $this->assertSame(20000000.0, (float) $quotation->dpp);
        $this->assertSame(2200000.0, (float) $quotation->ppn_amount);
        $this->assertSame(22200000.0, (float) $quotation->total);
    }

    public function test_updating_an_approved_quotation_throws_and_changes_nothing(): void
    {
        $quotation = $this->makeQuotation();
        $quotation->submit();
        $quotation->approve($this->makeUser());

        try {
            $this->quotations()->update($quotation, [
                'discount_amount' => 5000000,
                'items' => [['description' => 'Diskon nakal', 'qty' => 1, 'unit_price' => 1]],
            ]);
            $this->fail('Expected LogicException for editing an approved quotation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        $fresh = Quotation::query()->findOrFail($quotation->id);

        $this->assertSame(DocumentStatus::Approved, $fresh->status);
        $this->assertSame(0.0, (float) $fresh->discount_amount);
        $this->assertSame(25000000.0, (float) $fresh->subtotal);
        $this->assertSame(2, $fresh->items()->count());
    }

    public function test_a_rejected_quotation_is_editable_again(): void
    {
        $quotation = $this->makeQuotation();
        $quotation->submit();
        $quotation->reject($this->makeUser(), 'Harga di atas pagu.');

        $this->quotations()->update($quotation, ['discount_amount' => 1000000]);

        // 25.000.000 - 1.000.000 = 24.000.000
        $this->assertSame(24000000.0, (float) $quotation->refresh()->dpp);
    }

    public function test_deleting_a_submitted_quotation_throws_and_keeps_the_row(): void
    {
        $quotation = $this->makeQuotation();
        $quotation->submit();

        $this->expectException(LogicException::class);

        try {
            $this->quotations()->delete($quotation);
        } finally {
            $this->assertNotNull(Quotation::query()->find($quotation->id));
        }
    }
}
