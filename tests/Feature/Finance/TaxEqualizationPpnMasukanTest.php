<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Modules\Finance\Services\TaxEqualizationService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Ekualisasi PPN masukan — DPP tagihan berfaktur vs total DPP tagihan.
 *
 * The three figures are a partition of the same population (every approved
 * bill either carries a faktur or does not), so the residual is provably zero
 * on clean data — and asserted, not assumed. The advance case pins the one
 * netting fact the sheet leans on: ApBillService prices the FINAL bill net of
 * its approved uang muka, so summing stored DPP counts the advance exactly
 * once and no netting row is needed.
 */
class TaxEqualizationPpnMasukanTest extends ErpTestCase
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
        return app(TaxEqualizationService::class)->ppnMasukan($year);
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

    public function test_bills_with_and_without_faktur_partition_the_total(): void
    {
        $pkp = $this->makeVendor();
        $nonPkp = $this->makeVendor(['name' => 'CV Tukang Harian', 'is_pkp' => false]);

        // Berfaktur: DPP 100jt + PPN 11jt.
        $this->approveBill($this->apBills()->create([
            'vendor_id' => $pkp->id,
            'bill_date' => '2026-02-10',
            'description' => 'Material berfaktur',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
            'faktur_pajak_no' => '010.000-26.00000011',
            'vendor_invoice_no' => 'SDU-001',
        ]));

        // Tanpa faktur (vendor non-PKP): DPP 50jt, PPN nihil.
        $this->approveBill($this->apBills()->create([
            'vendor_id' => $nonPkp->id,
            'bill_date' => '2026-03-15',
            'description' => 'Upah borongan tanpa faktur',
            'dpp' => 50000000,
            'ppn_amount' => 0,
            'vendor_invoice_no' => 'CVT-001',
        ]));

        $sheet = $this->sheet(2026);

        $this->assertEqualsWithDelta(150000000.0, $this->row($sheet, 'Total DPP tagihan vendor disetujui')['buku'], 0.01);
        $this->assertEqualsWithDelta(100000000.0, $this->row($sheet, 'DPP tagihan berfaktur pajak')['spt'], 0.01);

        $withoutFaktur = $this->row($sheet, 'Tagihan tanpa faktur pajak');
        $this->assertSame('derived', $withoutFaktur['kind']);
        $this->assertEqualsWithDelta(50000000.0, $withoutFaktur['selisih'], 0.01);

        // 150 - 100 - 50 = 0, printed rather than hidden.
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        $this->assertEqualsWithDelta(11000000.0, $this->row($sheet, 'PPN masukan atas tagihan berfaktur')['spt'], 0.01);
    }

    /**
     * Uang muka 20jt + tagihan final atas PO 100jt: the final bill's stored
     * DPP is already 80jt (ApBillService nets the approved advance), so total
     * DPP is 100jt — the advance counted once, never twice.
     */
    public function test_an_advance_and_its_final_bill_count_the_po_exactly_once(): void
    {
        $vendor = $this->makeVendor();
        $po = $this->makePurchaseOrder($vendor); // DPP 100jt + PPN 11jt

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 20000000,
            'bill_date' => '2026-04-01',
            'faktur_pajak_no' => '010.000-26.00000012',
            'vendor_invoice_no' => 'SDU-DP-1',
        ]));

        $final = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-05-10',
            'faktur_pajak_no' => '010.000-26.00000013',
            'vendor_invoice_no' => 'SDU-002',
        ]));

        // The netting fact itself, pinned before the sheet reads it.
        $this->assertEqualsWithDelta(80000000.0, (float) $final->dpp, 0.01);

        $sheet = $this->sheet(2026);

        $this->assertEqualsWithDelta(100000000.0, $this->row($sheet, 'Total DPP tagihan vendor disetujui')['buku'], 0.01);
        $this->assertEqualsWithDelta(100000000.0, $this->row($sheet, 'DPP tagihan berfaktur pajak')['spt'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        // The advance is inside the total, and the sheet says so.
        $this->assertEqualsWithDelta(20000000.0, $this->row($sheet, 'Uang muka vendor di dalam total')['buku'], 0.01);
    }

    public function test_a_cancelled_bill_holding_a_faktur_is_warned_never_counted(): void
    {
        $vendor = $this->makeVendor();

        $cancelled = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-06-05',
            'description' => 'Tagihan keliru',
            'dpp' => 15000000,
            'ppn_amount' => 1650000,
            'faktur_pajak_no' => '010.000-26.00000014',
            'vendor_invoice_no' => 'SDU-003',
        ]));
        $this->apBills()->cancel($cancelled, $this->financeApprover(), 'Tagihan dobel');

        $live = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-06-20',
            'description' => 'Tagihan benar',
            'dpp' => 30000000,
            'ppn_amount' => 3300000,
            'faktur_pajak_no' => '010.000-26.00000015',
            'vendor_invoice_no' => 'SDU-004',
        ]));

        $sheet = $this->sheet(2026);

        $this->assertEqualsWithDelta(30000000.0, $this->row($sheet, 'Total DPP tagihan vendor disetujui')['buku'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        $this->assertTrue(
            collect($sheet['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'dibatalkan')),
            'A cancelled bill still holding a faktur must be flagged — its PPN must not be credited.'
        );
    }

    public function test_an_empty_year_says_so(): void
    {
        $sheet = $this->sheet(2030);

        $this->assertSame([], $sheet['rows']);
        $this->assertNull($sheet['residual']);
        $this->assertTrue(
            collect($sheet['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'Tidak ada')),
        );
    }
}
