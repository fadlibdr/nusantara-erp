<?php

namespace Tests\Unit\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ArInvoice;
use Tests\ErpTestCase;

/**
 * AR invoice arithmetic, independent of the ledger:
 *
 *   ppn   = dpp * ppn_rate / 100
 *   total = dpp + ppn - retention_withheld
 *
 * plus the terbilang line every Indonesian invoice must carry.
 */
class ArInvoiceMathTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer);
    }

    /**
     * A manual (non-termin) invoice; only dpp/rate/retention vary per test.
     */
    private function manualInvoice(array $data = []): ArInvoice
    {
        return $this->arInvoices()->create(array_merge([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Penagihan progres pekerjaan Maret 2026',
            'dpp' => 1000000000,
        ], $data));
    }

    public function test_ppn_is_eleven_percent_of_dpp(): void
    {
        $invoice = $this->manualInvoice(['dpp' => 1000000000]);

        // 1.000.000.000 * 11 / 100 = 110.000.000
        $this->assertSame(11.0, (float) $invoice->ppn_rate);
        $this->assertSame(110000000.0, (float) $invoice->ppn_amount);
    }

    public function test_total_is_dpp_plus_ppn_minus_retention(): void
    {
        $invoice = $this->manualInvoice([
            'dpp' => 1000000000,
            'retention_withheld' => 50000000, // retensi 5% dari DPP
        ]);

        // 1.000.000.000 + 110.000.000 - 50.000.000 = 1.060.000.000
        $this->assertSame(1060000000.0, (float) $invoice->total);
    }

    public function test_terbilang_spells_the_total_in_indonesian(): void
    {
        $invoice = $this->manualInvoice([
            'dpp' => 1000000000,
            'retention_withheld' => 50000000,
        ]);

        // Terbilang selalu mengeja TOTAL tagihan, bukan DPP.
        $this->assertSame('Satu miliar enam puluh juta rupiah', $invoice->terbilang);
    }

    public function test_ppn_is_rounded_to_two_decimals(): void
    {
        $invoice = $this->manualInvoice(['dpp' => 1234567.89]);

        // 1.234.567,89 * 11 / 100 = 135.802,4679 -> dibulatkan 135.802,47
        $this->assertSame(135802.47, (float) $invoice->ppn_amount);
        // 1.234.567,89 + 135.802,47 = 1.370.370,36
        $this->assertSame(1370370.36, (float) $invoice->total);
    }

    public function test_a_zero_rate_invoice_carries_no_ppn(): void
    {
        $invoice = $this->manualInvoice(['dpp' => 250000000, 'ppn_rate' => 0]);

        $this->assertSame(0.0, (float) $invoice->ppn_amount);
        $this->assertSame(250000000.0, (float) $invoice->total);
    }

    public function test_retention_greater_than_the_dpp_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Retention withheld cannot exceed the invoice DPP.');

        try {
            $this->manualInvoice([
                'dpp' => 100000000,
                'retention_withheld' => 100000000.01,
            ]);
        } finally {
            $this->assertDatabaseCount('fin_ar_invoices', 0);
        }
    }

    public function test_retention_exactly_equal_to_the_dpp_is_allowed(): void
    {
        $invoice = $this->manualInvoice([
            'dpp' => 100000000,
            'retention_withheld' => 100000000,
        ]);

        // 100.000.000 + 11.000.000 - 100.000.000 = 11.000.000 (tinggal PPN)
        $this->assertSame(11000000.0, (float) $invoice->total);
    }

    public function test_a_new_invoice_starts_as_an_unpaid_draft(): void
    {
        $invoice = $this->manualInvoice(['dpp' => 100000000]);

        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame(0.0, (float) $invoice->amount_paid);
        $this->assertNull($invoice->paid_at);
        // outstanding = total - amount_paid = 111.000.000 - 0
        $this->assertSame(111000000.0, $invoice->outstanding());
        $this->assertFalse($invoice->isFullyPaid());
        $this->assertNotEmpty($invoice->code);
    }

    public function test_the_due_date_follows_the_customer_payment_term(): void
    {
        $slowPayer = $this->makeCustomer(['name' => 'PT Bayar Lambat', 'payment_term_days' => 45]);
        $contract = $this->makeContract($slowPayer, ['title' => 'ELV Bank Artha']);

        $invoice = $this->arInvoices()->create([
            'customer_id' => $slowPayer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Termin 1',
            'dpp' => 100000000,
        ]);

        // 2026-03-10 + 45 hari = 2026-04-24
        $this->assertSame('2026-04-24', $invoice->due_date->toDateString());
    }

    // ------------------------------------------------------------ settings layer

    public function test_the_default_ppn_rate_comes_from_the_settings_layer(): void
    {
        $this->setSetting('tax.ppn_rate', 12);

        $invoice = $this->manualInvoice(['dpp' => 1000000000]);

        // 1.000.000.000 * 12 / 100 = 120.000.000 => total 1.120.000.000
        $this->assertSame(12.0, (float) $invoice->ppn_rate);
        $this->assertSame(120000000.0, (float) $invoice->ppn_amount);
        $this->assertSame(1120000000.0, (float) $invoice->total);
    }

    public function test_raising_the_ppn_rate_does_not_rewrite_an_existing_invoice(): void
    {
        $before = $this->manualInvoice(['dpp' => 1000000000]);

        $this->setSetting('tax.ppn_rate', 12);

        // Dokumen menyimpan tarif saat dibuat: yang lama tetap 11%.
        $this->assertSame(11.0, (float) $before->fresh()->ppn_rate);
        $this->assertSame(110000000.0, (float) $before->fresh()->ppn_amount);
    }

    public function test_an_explicit_rate_wins_over_the_settings_default(): void
    {
        $this->setSetting('tax.ppn_rate', 12);

        $invoice = $this->manualInvoice(['dpp' => 1000000000, 'ppn_rate' => 11]);

        $this->assertSame(110000000.0, (float) $invoice->ppn_amount);
    }

    // ------------------------------------------------------------ edit guards

    public function test_updating_a_draft_invoice_recalculates_ppn_total_and_terbilang(): void
    {
        $invoice = $this->manualInvoice(['dpp' => 1000000000]);

        $updated = $this->arInvoices()->update($invoice, [
            'dpp' => 200000000,
            'retention_withheld' => 10000000,
        ]);

        // 200.000.000 * 11% = 22.000.000; 200.000.000 + 22.000.000 - 10.000.000 = 212.000.000
        $this->assertSame(22000000.0, (float) $updated->ppn_amount);
        $this->assertSame(212000000.0, (float) $updated->total);
        $this->assertSame('Dua ratus dua belas juta rupiah', $updated->terbilang);
    }

    public function test_a_submitted_invoice_can_no_longer_be_edited_or_deleted(): void
    {
        $invoice = $this->manualInvoice(['dpp' => 1000000000]);
        $invoice->submit($this->financeUser());

        try {
            $this->arInvoices()->update($invoice, ['dpp' => 1]);
            $this->fail('Editing a submitted invoice should be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        try {
            $this->arInvoices()->delete($invoice);
            $this->fail('Deleting a submitted invoice should be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertSame(1000000000.0, (float) $fresh->dpp);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_a_faktur_pajak_number_can_only_be_registered_on_an_approved_invoice(): void
    {
        $invoice = $this->manualInvoice(['dpp' => 100000000]);

        $this->expectExceptionMessage('Faktur pajak can only be set on an approved invoice');

        try {
            $this->arInvoices()->registerFakturPajak($invoice, '010.000-26.00000001');
        } finally {
            $this->assertNull($invoice->fresh()->faktur_pajak_no);
        }
    }
}
