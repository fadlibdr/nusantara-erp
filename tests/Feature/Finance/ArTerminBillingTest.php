<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ArInvoice;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Billing one termin of a contract. The DPP either comes straight from the
 * termin's rupiah amount or, when that is left at zero, from
 * contract value * termin percent / 100. A termin may only be billed once, and
 * only when the contract itself is approved.
 */
class ArTerminBillingTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        // Nilai kontrak 10.000.000.000, retensi 5%, PPN 11%.
        $this->contract = $this->makeContract($this->customer, ['value' => 10000000000]);
    }

    public function test_dpp_comes_from_the_termin_amount_when_it_is_set(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'Termin 1 — Progres 30%', 30, 3500000000);

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
        ]);

        // Amount menang atas persentase: 3.500.000.000, bukan 30% x 10 M = 3 M.
        $this->assertSame(3500000000.0, (float) $invoice->dpp);
        // 3.500.000.000 * 11% = 385.000.000 => total 3.885.000.000
        $this->assertSame(385000000.0, (float) $invoice->ppn_amount);
        $this->assertSame(3885000000.0, (float) $invoice->total);
    }

    public function test_dpp_falls_back_to_contract_value_times_percent_when_the_amount_is_zero(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
        ]);

        // 10.000.000.000 * 20 / 100 = 2.000.000.000
        $this->assertSame(2000000000.0, (float) $invoice->dpp);
        // 2.000.000.000 * 11% = 220.000.000 => total 2.220.000.000
        $this->assertSame(220000000.0, (float) $invoice->ppn_amount);
        $this->assertSame(2220000000.0, (float) $invoice->total);
    }

    public function test_the_invoice_snapshots_the_contract_ppn_rate(): void
    {
        $contract = $this->makeContract($this->customer, ['value' => 1000000000, 'ppn_rate' => 12.0]);
        $termin = $this->makeTermin($contract, 1, 'Termin tunggal', 100, 0);

        $invoice = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);

        // Tarif kontrak (12%) menang atas default settings (11%).
        $this->assertSame(12.0, (float) $invoice->ppn_rate);
        // 1.000.000.000 * 12 / 100 = 120.000.000
        $this->assertSame(120000000.0, (float) $invoice->ppn_amount);
    }

    public function test_withholding_retention_uses_the_contract_percentage(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]);

        // DPP 2.000.000.000 * retention_pct 5% = 100.000.000
        $this->assertSame(100000000.0, (float) $invoice->retention_withheld);
        // 2.000.000.000 + 220.000.000 - 100.000.000 = 2.120.000.000
        $this->assertSame(2120000000.0, (float) $invoice->total);
    }

    public function test_an_explicit_retention_amount_wins_over_the_contract_percentage(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);

        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
            'retention_withheld' => 75000000,
        ]);

        $this->assertSame(75000000.0, (float) $invoice->retention_withheld);
        // 2.000.000.000 + 220.000.000 - 75.000.000 = 2.145.000.000
        $this->assertSame(2145000000.0, (float) $invoice->total);
    }

    public function test_no_retention_is_withheld_unless_asked_for(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);

        $invoice = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);

        $this->assertSame(0.0, (float) $invoice->retention_withheld);
        $this->assertSame(2220000000.0, (float) $invoice->total);
    }

    public function test_the_invoice_inherits_the_project_attached_to_the_contract(): void
    {
        $project = $this->makeProject(['contract_id' => $this->contract->id, 'customer_id' => $this->customer->id]);
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);

        $invoice = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);

        $this->assertSame((int) $project->id, (int) $invoice->project_id);
        $this->assertSame((int) $this->customer->id, (int) $invoice->customer_id);
        $this->assertSame((int) $this->contract->id, (int) $invoice->contract_id);
    }

    // ------------------------------------------------------------ guards

    public function test_billing_the_same_termin_twice_is_refused(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);

        $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('An invoice already exists for termin "DP 20%".');

        try {
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-20']);
        } finally {
            $this->assertSame(1, ArInvoice::query()->where('termin_id', $termin->id)->count());
        }
    }

    public function test_a_termin_already_stamped_as_billed_cannot_be_billed_again(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0, ['billed_at' => '2026-02-01']);

        $this->expectExceptionMessage('is already billed');

        try {
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);
        } finally {
            $this->assertDatabaseCount('fin_ar_invoices', 0);
        }
    }

    public function test_a_cancelled_invoice_does_not_block_rebilling_the_termin(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);

        $first = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);
        $first->forceFill(['status' => DocumentStatus::Cancelled])->save();

        $second = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-20']);

        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertSame(2000000000.0, (float) $second->dpp);
    }

    public function test_billing_a_termin_of_a_draft_contract_is_refused(): void
    {
        $draftContract = $this->makeContract($this->customer, [
            'title' => 'Kontrak belum disetujui',
            'value' => 5000000000,
            'status' => DocumentStatus::Draft,
        ]);
        $termin = $this->makeTermin($draftContract, 1, 'DP 20%', 20, 0);

        $this->expectExceptionMessage('only approved contracts can be billed');

        try {
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);
        } finally {
            $this->assertDatabaseCount('fin_ar_invoices', 0);
            $this->assertNull($termin->fresh()->billed_at);
        }
    }

    public function test_billing_a_termin_of_a_cancelled_contract_is_refused(): void
    {
        $cancelled = $this->makeContract($this->customer, [
            'title' => 'Kontrak dibatalkan',
            'value' => 5000000000,
            'status' => DocumentStatus::Cancelled,
        ]);
        $termin = $this->makeTermin($cancelled, 1, 'DP 20%', 20, 0);

        $this->expectExceptionMessage('only approved contracts can be billed');

        try {
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);
        } finally {
            $this->assertDatabaseCount('fin_ar_invoices', 0);
        }
    }
}
