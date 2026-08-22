<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Services\RevenueRecognitionService;
use Modules\Finance\Services\TaxEqualizationService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Ekualisasi PPN keluaran — pendapatan menurut buku vs DPP faktur pajak.
 *
 * The sheet's whole claim is that the gap between the two is EXACTLY the
 * movement of the contract balance plus the cancellations that straddle the
 * year — so every scenario here is hand-computed and the residual is asserted
 * to the rupiah, zero included. A flipped sign on the "pendapatan diakui belum
 * ditagih" row would tell a pemeriksa the company under-reported when it
 * over-billed, which is worse than no worksheet at all; both directions are
 * therefore pinned in one year, side by side.
 */
class TaxEqualizationPpnKeluaranTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->seedLedger(2026);
    }

    private function sheet(int $year): array
    {
        return app(TaxEqualizationService::class)->ppnKeluaran($year);
    }

    /** @return array<string, mixed> */
    private function row(array $sheet, string $labelPrefix): array
    {
        foreach ($sheet['rows'] as $row) {
            if (str_starts_with($row['label'], $labelPrefix)) {
                return $row;
            }
        }

        $this->fail("Worksheet has no row starting with [{$labelPrefix}].");
    }

    private function hasRow(array $sheet, string $labelPrefix): bool
    {
        foreach ($sheet['rows'] as $row) {
            if (str_starts_with($row['label'], $labelPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One contract earning ahead of billing AND one billed ahead of earning,
     * measured by the same posted run — the sign test.
     */
    public function test_both_directions_of_the_contract_balance_reconcile_the_year(): void
    {
        $customer = $this->makeCustomer();

        // Kontrak A: biaya 300jt / EAC 600jt = 50% x 1 miliar = 500jt diakui,
        // tertagih 200jt -> aset kontrak +300jt.
        $contractA = $this->makeContract($customer, ['value' => 1000000000]);
        $projectA = $this->makeProject(['contract_id' => $contractA->id]);
        $this->projectCosts()->record($projectA->id, '2026-06-10', CostCategory::Material, 'test', 1, 'Biaya uji A', 300000000);
        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contractA->id,
            'invoice_date' => '2026-06-15',
            'description' => 'Termin 1 kontrak A',
            'dpp' => 200000000,
            'ppn_rate' => 11.0,
        ]));

        // Kontrak B: biaya 50jt / EAC 500jt = 10% x 1 miliar = 100jt diakui,
        // tertagih 300jt (uang muka) -> liabilitas kontrak -200jt.
        $contractB = $this->makeContract($customer, ['value' => 1000000000, 'title' => 'Kontrak B']);
        $projectB = $this->makeProject(['contract_id' => $contractB->id, 'name' => 'Proyek B']);
        $this->projectCosts()->record($projectB->id, '2026-06-05', CostCategory::Material, 'test', 2, 'Biaya uji B', 50000000);
        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contractB->id,
            'invoice_date' => '2026-06-08',
            'description' => 'Uang muka kontrak B',
            'dpp' => 300000000,
            'ppn_rate' => 11.0,
        ]));

        $poc = app(RevenueRecognitionService::class);
        $run = $poc->calculate(2026, 6, $this->financeUser(), [
            $contractA->id => 600000000.0,
            $contractB->id => 500000000.0,
        ]);
        $poc->post($run, $this->financeApprover());

        $sheet = $this->sheet(2026);

        // Buku: 200jt + 300jt (invoice) + 300jt - 200jt (jurnal POC) = 600jt.
        $this->assertEqualsWithDelta(600000000.0, $this->row($sheet, 'Pendapatan menurut buku')['buku'], 0.01);
        // SPT: DPP invoice disetujui = 200jt + 300jt = 500jt.
        $this->assertEqualsWithDelta(500000000.0, $this->row($sheet, 'DPP faktur pajak keluaran')['spt'], 0.01);

        // The two directions, each with its own sign.
        $this->assertEqualsWithDelta(300000000.0, $this->row($sheet, 'Pendapatan diakui belum ditagih')['selisih'], 0.01);
        $this->assertEqualsWithDelta(-200000000.0, $this->row($sheet, 'Penagihan mendahului pendapatan')['selisih'], 0.01);

        // 600 - 500 - 300 + 200 = 0, computed, never forced.
        $this->assertNotNull($sheet['residual']);
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        // June has a posted run, so no "bulan tanpa run" warning row.
        $this->assertFalse($this->hasRow($sheet, 'Pendapatan konstruksi/integrasi pada bulan tanpa run'));
    }

    public function test_revenue_in_a_month_without_a_posted_run_is_a_warning_row_not_silence(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['value' => 1000000000]);
        $this->makeProject(['contract_id' => $contract->id]);

        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-05-12',
            'description' => 'Termin tanpa run',
            'dpp' => 150000000,
            'ppn_rate' => 11.0,
        ]));

        $sheet = $this->sheet(2026);

        // Buku = SPT = 150jt: the ledger runs billing basis while no run posts.
        $this->assertEqualsWithDelta(150000000.0, $this->row($sheet, 'Pendapatan menurut buku')['buku'], 0.01);
        $this->assertEqualsWithDelta(150000000.0, $this->row($sheet, 'DPP faktur pajak keluaran')['spt'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        // The month is named, and the amount is the month's own movement.
        $warning = $this->row($sheet, 'Pendapatan konstruksi/integrasi pada bulan tanpa run');
        $this->assertSame('warning', $warning['kind']);
        $this->assertStringContainsString('Mei', $warning['label']);
        $this->assertEqualsWithDelta(150000000.0, $warning['buku'], 0.01);
    }

    /**
     * 4-1300 is invoiced directly (billing basis by design, no POC run) and
     * must appear on BOTH sides rather than being lost by a sheet that only
     * reads the two POC accounts.
     */
    public function test_service_revenue_on_4_1300_is_not_lost(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, [
            'scope_type' => 'maintenance',
            'value' => 480000000,
            'title' => 'Pemeliharaan CCTV',
        ]);

        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-07-01',
            'description' => 'Pemeliharaan triwulan III',
            'dpp' => 40000000,
            'ppn_rate' => 11.0,
        ]));

        $sheet = $this->sheet(2026);

        $this->assertEqualsWithDelta(40000000.0, $this->row($sheet, 'Pendapatan menurut buku')['buku'], 0.01);
        $this->assertEqualsWithDelta(40000000.0, $this->row($sheet, 'DPP faktur pajak keluaran')['spt'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        // Maintenance months never have runs and must not cry wolf.
        $this->assertFalse($this->hasRow($sheet, 'Pendapatan konstruksi/integrasi pada bulan tanpa run'));
    }

    /**
     * Same-year cancellation nets to zero in the books and drops out of the
     * DPP — no reconciling row — but a spent faktur serial still demands its
     * nota pembatalan, which is the warning.
     */
    public function test_a_cancelled_invoice_with_a_faktur_warns_about_the_nota_pembatalan(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['value' => 1000000000]);
        $this->makeProject(['contract_id' => $contract->id]);

        $cancelled = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-04-10',
            'description' => 'Termin keliru',
            'dpp' => 100000000,
            'ppn_rate' => 11.0,
        ]));
        $this->arInvoices()->registerFakturPajak($cancelled, '010.000-26.00000001');
        $this->arInvoices()->cancel($cancelled, $this->financeApprover(), 'Termin salah hitung');

        $live = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-07-05',
            'description' => 'Termin benar',
            'dpp' => 80000000,
            'ppn_rate' => 11.0,
        ]));

        $sheet = $this->sheet(2026);

        // April nets to zero (credit + reversal on the invoice's own date), so
        // only the July termin remains on both sides.
        $this->assertEqualsWithDelta(80000000.0, $this->row($sheet, 'Pendapatan menurut buku')['buku'], 0.01);
        $this->assertEqualsWithDelta(80000000.0, $this->row($sheet, 'DPP faktur pajak keluaran')['spt'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);
        $this->assertFalse($this->hasRow($sheet, 'Invoice dibatalkan'));
        $this->assertFalse($this->hasRow($sheet, 'Pembalikan tahun ini'));

        $this->assertTrue(
            collect($sheet['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'nota pembatalan')),
            'The spent faktur serial must be flagged for its nota pembatalan.'
        );
    }

    /**
     * A cancellation whose reversal lands in the NEXT year (the invoice's own
     * period is closed, so JournalService::reversalDate falls to today) leaves
     * this year's books carrying revenue the DPP no longer reports — and the
     * next year's books carrying a debit with no invoice. Both years must
     * derive their own row and still close to zero.
     */
    public function test_a_cross_year_cancellation_derives_a_row_in_each_year(): void
    {
        Carbon::setTestNow('2026-12-01 09:00:00');

        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['value' => 1000000000]);
        $this->makeProject(['contract_id' => $contract->id]);

        $invoice = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-11-10',
            'description' => 'Termin November',
            'dpp' => 120000000,
            'ppn_rate' => 11.0,
        ]));

        // November closes; the cancellation only happens in January.
        FiscalPeriod::query()->where('year', 2026)->where('month', 11)->update(['status' => 'closed']);
        Carbon::setTestNow('2027-01-15 09:00:00');
        $this->openFiscalYear(2027);
        $this->arInvoices()->cancel($invoice, $this->financeApprover(), 'Dibatalkan pemberi kerja');

        $sheet2026 = $this->sheet(2026);

        // 2026 books still carry the 120jt credit; the DPP side reports nothing
        // because the invoice is cancelled NOW. The gap is the derived row.
        $this->assertEqualsWithDelta(120000000.0, $this->row($sheet2026, 'Pendapatan menurut buku')['buku'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->row($sheet2026, 'DPP faktur pajak keluaran')['spt'], 0.01);
        $this->assertEqualsWithDelta(120000000.0, $this->row($sheet2026, 'Invoice dibatalkan setelah tahun berjalan')['selisih'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet2026['residual']['amount'], 0.01);

        $sheet2027 = $this->sheet(2027);

        // 2027 carries only the reversal debit: buku -120jt, SPT 0.
        $this->assertEqualsWithDelta(-120000000.0, $this->row($sheet2027, 'Pendapatan menurut buku')['buku'], 0.01);
        $this->assertEqualsWithDelta(-120000000.0, $this->row($sheet2027, 'Pembalikan tahun ini atas invoice tahun sebelumnya')['selisih'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet2027['residual']['amount'], 0.01);
    }

    /**
     * Revenue booked by hand (a manual JV) has no faktur behind it — it must
     * surface as its own derived row instead of poisoning the residual.
     */
    public function test_manual_journal_revenue_is_its_own_derived_row(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['value' => 1000000000]);
        $this->makeProject(['contract_id' => $contract->id]);

        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Termin 1',
            'dpp' => 200000000,
            'ppn_rate' => 11.0,
        ]));

        // JV manual: pendapatan lain-lain 25jt langsung ke 4-1100.
        $this->postJournal([
            ['1-1300', 25000000, 0],
            ['4-1100', 0, 25000000],
        ], '2026-05-20', 'Pendapatan klaim tambahan (JV manual)');

        $sheet = $this->sheet(2026);

        $this->assertEqualsWithDelta(225000000.0, $this->row($sheet, 'Pendapatan menurut buku')['buku'], 0.01);
        $this->assertEqualsWithDelta(200000000.0, $this->row($sheet, 'DPP faktur pajak keluaran')['spt'], 0.01);
        $this->assertEqualsWithDelta(25000000.0, $this->row($sheet, 'Pendapatan dibukukan di luar penagihan')['selisih'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);
    }

    public function test_an_empty_year_says_so_instead_of_printing_a_zero_table(): void
    {
        $sheet = $this->sheet(2030);

        $this->assertSame([], $sheet['rows']);
        $this->assertNull($sheet['residual']);
        $this->assertTrue(
            collect($sheet['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'Tidak ada data')),
        );
    }
}
