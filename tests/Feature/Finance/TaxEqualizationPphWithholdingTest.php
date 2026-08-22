<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Modules\Finance\Enums\WithholdingType;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\TaxEqualizationService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Ekualisasi PPh dipotong — the two panels of worksheet 4.
 *
 * Panel A (dipotong perusahaan): the e-Bupot base is reconstructed from each
 * bill's own pph_amount and its tax row's rate — which recovers the FULL
 * opname gross even though the bill's stored DPP is net of the uang muka it
 * consumed — and compared against 5-1300, the account every subcon bill's
 * cost leg debits. Panel B (dipotong pelanggan) is a SOFT comparison by
 * design: the customer withholds on RECEIPT while the books earn by progress,
 * so both bases are printed and the difference is labelled as timing, never
 * pushed into a residual.
 */
class TaxEqualizationPphWithholdingTest extends ErpTestCase
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
        return app(TaxEqualizationService::class)->pphWithholding($year);
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

    public function test_the_bupot_base_reconciles_against_5_1300_with_a_zero_residual(): void
    {
        $this->makePphFinalTax();
        $subcon = $this->makeVendor(['name' => 'CV Karya Sipil Sejahtera', 'is_subcontractor' => true]);
        $project = $this->makeProject();

        // Opname 100jt, PPh final 2,65% = 2.650.000 — billed and approved:
        // Dr 5-1300 100jt on the bill journal, base = 2.650.000 / 2,65% = 100jt.
        $spk1 = $this->makeSubcontract($subcon, ['project_id' => $project->id]);
        $claim1 = $this->makeProgressClaim($spk1);
        $this->approveBill($this->apBills()->createFromSubconClaim($claim1, [
            'bill_date' => '2026-04-05',
            'vendor_invoice_no' => 'KSS-001',
        ]));

        // SPK kedua yang opnamenya tidak memotong PPh (pph_amount 0 pada
        // klaim): opname 40jt billed with NO withholding — 5-1300 grows, the
        // base does not, and the gap must surface as its own derived row.
        $spk2 = $this->makeSubcontract($subcon, [
            'project_id' => $project->id,
            'title' => 'Pekerjaan galian',
            'pph_rate' => 0,
        ]);
        $claim2 = $this->makeProgressClaim($spk2, [
            'gross_amount' => 40000000,
            'retention_amount' => 0,
            'net_before_tax' => 40000000,
            'ppn_amount' => 0,
            'pph_amount' => 0,
            'net_payable' => 40000000,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        $this->approveBill($this->apBills()->createFromSubconClaim($claim2, [
            'bill_date' => '2026-05-02',
            'vendor_invoice_no' => 'KSS-002',
        ]));

        // JV manual 9jt ke 5-1300 (akrual opname belum ditagih) — sumber di
        // luar tagihan vendor, baris turunannya sendiri.
        $this->postJournal([
            ['5-1300', 9000000, 0],
            ['2-1100', 0, 9000000],
        ], '2026-06-15', 'Akrual opname (JV manual)');

        // Opname 25jt approved but NOT billed: no cost, no withholding yet —
        // a warning row, because the PPh only falls due when it is billed.
        $this->makeProgressClaim($spk1, [
            'gross_amount' => 25000000,
            'retention_amount' => 1250000,
            'net_before_tax' => 23750000,
            'ppn_amount' => 2750000,
            'pph_amount' => 662500,
            'net_payable' => 24837500,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        $sheet = $this->sheet(2026);

        // 5-1300 = 100 + 40 + 9 = 149jt.
        $this->assertEqualsWithDelta(149000000.0, $this->row($sheet, 'Beban subkontraktor menurut buku')['buku'], 0.01);
        // Basis bukti potong = 2.650.000 / 2,65% = 100jt.
        $this->assertEqualsWithDelta(100000000.0, $this->row($sheet, 'Basis pemotongan PPh final konstruksi')['spt'], 0.01);
        $this->assertEqualsWithDelta(2650000.0, $this->row($sheet, 'PPh final konstruksi dipotong perusahaan')['spt'], 0.01);

        $this->assertEqualsWithDelta(40000000.0, $this->row($sheet, 'Beban subkon tanpa pemotongan PPh final')['selisih'], 0.01);
        $this->assertEqualsWithDelta(9000000.0, $this->row($sheet, 'Beban subkontraktor dari sumber selain tagihan vendor')['selisih'], 0.01);

        // 149 - 100 - 40 - 9 = 0.
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        // The unbilled opname is a warning row carrying its billable value.
        $unbilled = $this->row($sheet, 'Opname subkon disetujui belum ditagihkan');
        $this->assertSame('warning', $unbilled['kind']);
        $this->assertEqualsWithDelta(25000000.0, $unbilled['buku'], 0.01);
    }

    public function test_pph_23_bases_are_printed_without_a_forced_book_comparison(): void
    {
        $this->makePph23Tax();
        $vendor = $this->makeVendor(['name' => 'PT Jasa Boga Prima']);

        // Jasa katering 30jt, PPh 23 2% = 600.000. Its cost lands wherever the
        // bill says (here overhead) — many accounts, so the sheet prints the
        // base as info instead of pretending one account covers it.
        $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-08',
            'description' => 'Katering proyek',
            'dpp' => 30000000,
            'ppn_amount' => 3300000,
            'pph_tax_id' => (int) Tax::query()->where('code', 'PPH23')->value('id'),
            'vendor_invoice_no' => 'JBP-001',
            'faktur_pajak_no' => '010.000-26.00000021',
        ]));

        $sheet = $this->sheet(2026);

        $base23 = $this->row($sheet, 'Basis pemotongan PPh 23 jasa');
        $this->assertSame('info', $base23['kind']);
        $this->assertEqualsWithDelta(30000000.0, $base23['spt'], 0.01);
        $this->assertEqualsWithDelta(600000.0, $this->row($sheet, 'PPh 23 dipotong perusahaan')['spt'], 0.01);
    }

    public function test_customer_withholding_is_a_soft_comparison_printing_both_bases(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['value' => 10000000000]);
        $bank = $this->makeBankAccount('1-1210');

        // Termin 1: DPP 1 miliar + PPN 110jt, dibayar penuh dengan potongan
        // PPh final 26,5jt (2,65%) + PPN wapu 110jt -> kas 973,5jt.
        $invoice1 = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Termin 1',
            'dpp' => 1000000000,
            'ppn_rate' => 11.0,
        ]));
        $receipt = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-04-05',
            'bank_account_id' => $bank->id,
            'amount' => 973500000,
        ]);
        $this->payments()->post($receipt, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice1->id, 'amount' => 1110000000],
        ], null, [
            [
                'ar_invoice_id' => $invoice1->id,
                'type' => WithholdingType::PphFinal->value,
                'amount' => 26500000,
                'certificate_no' => '0031/PPH4-2/IV/2026',
                'certificate_date' => '2026-04-05',
            ],
            [
                'ar_invoice_id' => $invoice1->id,
                'type' => WithholdingType::PpnWapu->value,
                'amount' => 110000000,
                'certificate_no' => null,
                'certificate_date' => '2026-04-05',
            ],
        ]);

        // Termin 2: 500jt diakui September, BELUM diterima — the timing gap.
        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-07-15',
            'description' => 'Termin 2',
            'dpp' => 500000000,
            'ppn_rate' => 11.0,
        ]));

        $sheet = $this->sheet(2026);

        // Bukti potong PPh final saja — wapu 110jt must NOT leak in.
        $this->assertEqualsWithDelta(26500000.0, $this->row($sheet, 'PPh final dipotong pelanggan')['spt'], 0.01);
        // Basis tercakup = 26,5jt / 2,65% = 1 miliar.
        $this->assertEqualsWithDelta(1000000000.0, $this->row($sheet, 'Basis penerimaan yang dicakup bukti potong')['spt'], 0.01);
        // Pendapatan konstruksi menurut buku = 1,5 miliar.
        $this->assertEqualsWithDelta(1500000000.0, $this->row($sheet, 'Pendapatan jasa konstruksi menurut buku')['buku'], 0.01);
        // Seharusnya 2,65% x 1,5 miliar = 39,75jt.
        $this->assertEqualsWithDelta(39750000.0, $this->row($sheet, 'PPh final seharusnya')['buku'], 0.01);
        // Selisih lunak = 26,5 - 39,75 = -13,25jt, dengan tandanya.
        $selisih = $this->row($sheet, 'Selisih PPh final dipotong vs seharusnya');
        $this->assertSame('info', $selisih['kind']);
        $this->assertEqualsWithDelta(-13250000.0, $selisih['selisih'], 0.01);

        $this->assertTrue(
            collect($sheet['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'perbedaan waktu')),
            'The sheet must say out loud that this comparison is soft (withheld on receipt, earned by progress).'
        );

        // Panel A has no data this year — its residual honestly stays null.
        $this->assertNull($sheet['residual']);
    }

    public function test_a_reversed_receipt_takes_its_certificate_out_of_the_count(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['value' => 1000000000]);
        $bank = $this->makeBankAccount('1-1210');

        $invoice = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Termin 1',
            'dpp' => 200000000,
            'ppn_rate' => 11.0,
        ]));
        $receipt = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-04-05',
            'bank_account_id' => $bank->id,
            'amount' => 216700000,
        ]);
        // Kas = 222jt - 5,3jt PPh final = 216,7jt.
        $this->payments()->post($receipt, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 222000000],
        ], null, [
            [
                'ar_invoice_id' => $invoice->id,
                'type' => WithholdingType::PphFinal->value,
                'amount' => 5300000,
                'certificate_no' => '0032/PPH4-2/IV/2026',
                'certificate_date' => '2026-04-05',
            ],
        ]);
        $this->payments()->reverse($receipt->refresh(), $this->financeApprover(), 'Salah alokasi');

        $sheet = $this->sheet(2026);

        // The reversed receipt's certificate no longer stands: withheld = 0,
        // printed as the stored fact it is — construction revenue exists, so
        // the panel is NOT "no data".
        $this->assertEqualsWithDelta(0.0, $this->row($sheet, 'PPh final dipotong pelanggan')['spt'], 0.01);
        $this->assertEqualsWithDelta(200000000.0, $this->row($sheet, 'Pendapatan jasa konstruksi menurut buku')['buku'], 0.01);
    }

    public function test_an_empty_year_says_so_for_both_panels(): void
    {
        $sheet = $this->sheet(2030);

        $this->assertSame([], $sheet['rows']);
        $this->assertNull($sheet['residual']);
        $this->assertTrue(
            collect($sheet['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'Tidak ada')),
        );
    }
}
