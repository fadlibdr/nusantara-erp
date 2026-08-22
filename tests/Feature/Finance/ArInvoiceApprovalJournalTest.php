<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ArRetention;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Approving an AR invoice books
 *
 *   Dr 1-1300 Piutang Usaha      total
 *   Dr 1-1350 Piutang Retensi    retention_withheld
 *   Cr 4-1x00 Pendapatan         dpp
 *   Cr 2-1300 PPN Keluaran       ppn_amount
 *
 * which balances because total + retention = (dpp + ppn - retention) + retention.
 * It also opens the retention receivable and stamps the termin as billed.
 */
class ArInvoiceApprovalJournalTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer, ['value' => 10000000000]);
    }

    public function test_approval_books_receivable_revenue_and_output_vat(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]);

        $approved = $this->approveInvoice($invoice);

        // DPP 2.000.000.000, PPN 220.000.000, retensi 100.000.000 => total 2.120.000.000
        $this->assertSame(DocumentStatus::Approved, $approved->status);

        $journal = $this->singleJournalFor('ar_invoice', (int) $invoice->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        $this->assertSame(2120000000.0, $lines['1-1300']['debit']);
        $this->assertSame(100000000.0, $lines['1-1350']['debit']);
        $this->assertSame(2000000000.0, $lines['4-1100']['credit']);
        $this->assertSame(220000000.0, $lines['2-1300']['credit']);

        // 2.120.000.000 + 100.000.000 = 2.000.000.000 + 220.000.000 = 2.220.000.000
        $this->assertSame(2220000000.0, $journal->totalDebit());
        $this->assertSame(2220000000.0, $journal->totalCredit());
    }

    public function test_an_invoice_without_retention_books_only_three_lines(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->approveInvoice(
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10'])
        );

        $lines = $this->linesByAccount($this->singleJournalFor('ar_invoice', (int) $invoice->id));

        // Leg retensi bernilai nol dan dibuang oleh autoPost().
        $this->assertArrayNotHasKey('1-1350', $lines);
        $this->assertSame(2220000000.0, $lines['1-1300']['debit']);
        $this->assertSame(2000000000.0, $lines['4-1100']['credit']);
        $this->assertSame(220000000.0, $lines['2-1300']['credit']);
        $this->assertSame(0, ArRetention::query()->count());
    }

    public function test_approval_opens_the_retention_receivable_and_stamps_the_termin(): void
    {
        $project = $this->makeProject(['contract_id' => $this->contract->id]);
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]);

        $this->approveInvoice($invoice);

        $retention = ArRetention::query()->where('source_invoice_id', $invoice->id)->sole();

        // 2.000.000.000 * 5% = 100.000.000
        $this->assertSame(100000000.0, (float) $retention->amount);
        $this->assertFalse($retention->released);
        $this->assertNull($retention->released_at);
        $this->assertSame((int) $this->contract->id, (int) $retention->contract_id);
        $this->assertSame((int) $project->id, (int) $retention->project_id);

        $this->assertSame('2026-03-10', $termin->fresh()->billed_at->toDateString());
        $this->assertTrue($termin->fresh()->isBilled());
    }

    // ------------------------------------------------------------ revenue account by scope

    public function test_a_construction_project_credits_the_construction_revenue_account(): void
    {
        $this->assertRevenueAccountForProjectType('construction', '4-1100');
    }

    public function test_a_system_integration_project_credits_the_integration_revenue_account(): void
    {
        $this->assertRevenueAccountForProjectType('system_integration', '4-1200');
    }

    public function test_a_maintenance_project_credits_the_maintenance_revenue_account(): void
    {
        $this->assertRevenueAccountForProjectType('maintenance', '4-1300');
    }

    public function test_without_a_project_the_contract_scope_picks_the_revenue_account(): void
    {
        // Tidak ada proyek yang menunjuk kontrak ini, jadi scope_type yang dipakai.
        $contract = $this->makeContract($this->customer, [
            'title' => 'ELV & ICT 12 cabang Bank Artha',
            'scope_type' => 'system_integration',
            'value' => 1000000000,
        ]);
        $termin = $this->makeTermin($contract, 1, 'Termin tunggal', 100, 0);

        $invoice = $this->approveInvoice(
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10'])
        );

        $lines = $this->linesByAccount($this->singleJournalFor('ar_invoice', (int) $invoice->id));

        $this->assertNull($invoice->project_id);
        $this->assertSame(1000000000.0, $lines['4-1200']['credit']);
        $this->assertArrayNotHasKey('4-1100', $lines);
    }

    private function assertRevenueAccountForProjectType(string $projectType, string $expectedAccount): void
    {
        // Scope kontrak sengaja "maintenance" supaya terlihat bahwa tipe proyek
        // yang menang, bukan scope kontrak.
        $contract = $this->makeContract($this->customer, [
            'title' => "Kontrak {$projectType}",
            'scope_type' => 'maintenance',
            'value' => 1000000000,
        ]);
        $project = $this->makeProject(['contract_id' => $contract->id, 'type' => $projectType]);
        $termin = $this->makeTermin($contract, 1, 'Termin tunggal', 100, 0);

        $invoice = $this->approveInvoice(
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10'])
        );

        $lines = $this->linesByAccount($this->singleJournalFor('ar_invoice', (int) $invoice->id));

        // 1.000.000.000 DPP dikreditkan ke akun pendapatan sesuai tipe proyek.
        $this->assertSame(1000000000.0, $lines[$expectedAccount]['credit']);
        // Setiap baris jurnal membawa project_id untuk laba-rugi per proyek.
        $this->assertSame((int) $project->id, $lines[$expectedAccount]['project_id']);
    }

    // ------------------------------------------------------------ guards

    public function test_a_draft_invoice_cannot_be_approved_and_books_nothing(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot approve document');

        try {
            $this->arInvoices()->approve($invoice, $this->financeApprover());
        } finally {
            $this->assertSame(DocumentStatus::Draft, $invoice->fresh()->status);
            $this->assertDatabaseCount('fin_journals', 0);
            $this->assertNull($termin->fresh()->billed_at);
        }
    }

    public function test_a_closed_period_rolls_the_whole_approval_back(): void
    {
        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'closed']);

        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-10',
            'withhold_retention' => true,
        ]);
        $invoice->submit($this->financeUser());

        $this->expectExceptionMessage('Periode fiskal 2026-03 sudah ditutup');

        try {
            $this->arInvoices()->approve($invoice, $this->financeApprover());
        } finally {
            // Approval, jurnal, retensi dan cap termin harus ikut batal.
            $this->assertSame(DocumentStatus::Submitted, $invoice->fresh()->status);
            $this->assertSame(0, Journal::query()->count());
            $this->assertSame(0, ArRetention::query()->count());
            $this->assertNull($termin->fresh()->billed_at);
        }
    }

    public function test_the_faktur_pajak_number_can_be_registered_once_approved(): void
    {
        $termin = $this->makeTermin($this->contract, 1, 'DP 20%', 20, 0);
        $invoice = $this->approveInvoice(
            $this->arInvoices()->create(['termin_id' => $termin->id, 'invoice_date' => '2026-03-10'])
        );

        $registered = $this->arInvoices()->registerFakturPajak($invoice, '010.000-26.00000001');

        $this->assertSame('010.000-26.00000001', $registered->fresh()->faktur_pajak_no);
    }
}
