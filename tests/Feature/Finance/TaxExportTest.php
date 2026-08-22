<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Modules\Core\Models\Company;
use Modules\Core\Models\NumberSequence;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Tax;
use Modules\Finance\Services\TaxExportService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Statutory tax exports — e-Faktur (PPN keluaran) and e-Bupot (PPh dipotong).
 *
 * The property that matters is not "a file was produced". It is that the file
 * says the same thing the ledger says, and that anything which CANNOT be
 * exported is reported rather than silently missing — a tax file that is short
 * by one invoice looks exactly like a correct one until DJP disagrees.
 */
class TaxExportTest extends ErpTestCase
{
    use FinanceFixtures;

    private TaxExportService $exports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->exports = app(TaxExportService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
        ]);
    }

    /** DPP 100.000.000, PPN 11% = 11.000.000 */
    private function approvedInvoice(array $overrides = []): ArInvoice
    {
        $customer = $this->makeCustomer(array_merge([
            'name' => 'PT Graha Sentosa Propertindo',
            'npwp' => '01.234.567.8-011.000',
            'billing_address' => 'Jl. TB Simatupang Kav. 18',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
        ], $overrides['customer'] ?? []));

        $contract = $this->makeContract($customer);

        $invoice = $this->arInvoices()->create(array_merge([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'description' => 'Penagihan termin 1',
            'dpp' => 100_000_000,
            'ppn_rate' => 11.0,
            'invoice_date' => '2026-03-15',
        ], $overrides['invoice'] ?? []));

        $invoice = $this->approveInvoice($invoice);

        if (! array_key_exists('faktur', $overrides) || $overrides['faktur'] !== null) {
            $this->arInvoices()->registerFakturPajak(
                $invoice,
                $overrides['faktur'] ?? '010.000-26.00000001'
            );
        }

        return $invoice->refresh();
    }

    // ------------------------------------------------------------ e-Faktur

    public function test_an_approved_invoice_with_a_faktur_number_is_exported(): void
    {
        $this->approvedInvoice();

        $export = $this->exports->eFaktur(2026, 3);

        $this->assertSame(1, $export['summary']['exported']);
        $this->assertSame(0, $export['summary']['blocked']);
        $this->assertSame(100_000_000.0, $export['summary']['dpp']);
        $this->assertSame(11_000_000.0, $export['summary']['ppn']);   // 100jt x 11%
        $this->assertSame('efaktur-2026-03.csv', $export['filename']);
    }

    /**
     * 010.000-26.00000001 -> transaction code 01, replacement flag 0,
     * 13-digit serial 0002600000001.
     */
    public function test_the_faktur_number_is_split_into_the_fields_the_importer_expects(): void
    {
        $this->approvedInvoice();

        $fk = $this->recordOfType($this->exports->eFaktur(2026, 3)['csv'], 'FK');

        $this->assertSame('01', $fk[1], 'kode jenis transaksi');
        $this->assertSame('0', $fk[2], 'flag pengganti');
        $this->assertSame('0002600000001', $fk[3], 'nomor seri faktur pajak');
        $this->assertSame('3', $fk[4], 'masa pajak');
        $this->assertSame('2026', $fk[5], 'tahun pajak');
        $this->assertSame('15/03/2026', $fk[6], 'tanggal faktur');
    }

    /**
     * PMK 131/2024: a faktur for a non-luxury BKP/JKP reports DPP nilai lain —
     * 11/12 of the harga jual — with PPN at the statutory 12 % of THAT. The
     * rupiah of PPN is the same either way (11 % of 100jt == 12 % of 91.666.667),
     * which is exactly why writing the harga jual into JUMLAH_DPP went unnoticed:
     * only the DPP column and the implied tarif were wrong, by 9,09 % on the
     * figure an equalisasi compares against turnover.
     */
    public function test_the_file_reports_dpp_nilai_lain_with_ppn_at_the_statutory_rate(): void
    {
        $this->approvedInvoice();

        $csv = $this->exports->eFaktur(2026, 3)['csv'];
        $fk = $this->recordOfType($csv, 'FK');
        $of = $this->recordOfType($csv, 'OF');

        // Whole rupiah, no separators — what the importer expects.
        // 11.000.000 / 12% = 91.666.666,67 -> 91.666.667
        $this->assertSame('91666667', $fk[10], 'JUMLAH_DPP is the nilai lain');
        $this->assertSame('11000000', $fk[11]);
        $this->assertSame('91666667', $of[7], 'DPP on the item line');
        $this->assertSame('11000000', $of[8], 'PPN on the item line');

        // The harga jual is not lost: it stays on the item line as the price.
        $this->assertSame('100000000', $of[3], 'harga satuan');
        $this->assertSame('100000000', $of[5], 'harga total');
    }

    /**
     * The screen still reconciles against the ledger, because both figures are
     * reported: `dpp` is the harga jual the invoice and the GL hold, and
     * `dpp_faktur` is what the file carries.
     */
    public function test_the_export_reports_both_the_harga_jual_and_the_faktur_dpp(): void
    {
        $this->approvedInvoice();

        $export = $this->exports->eFaktur(2026, 3);

        $this->assertSame(100_000_000.0, $export['summary']['dpp']);
        $this->assertSame(91_666_666.67, $export['summary']['dpp_faktur']);
        $this->assertSame(100_000_000.0, $export['rows'][0]['dpp']);
        $this->assertSame(91_666_666.67, $export['rows'][0]['dpp_faktur']);
    }

    /**
     * An invoice raised at the headline 12 % (a luxury BKP, where nilai lain
     * does not apply) reports its harga jual unchanged — the DPP is derived from
     * the PPN actually charged, not by multiplying everything by 11/12.
     */
    public function test_an_invoice_at_the_headline_rate_reports_its_harga_jual_unchanged(): void
    {
        $this->approvedInvoice(['invoice' => ['ppn_rate' => 12.0]]);

        $fk = $this->recordOfType($this->exports->eFaktur(2026, 3)['csv'], 'FK');

        $this->assertSame('100000000', $fk[10]);
        $this->assertSame('12000000', $fk[11]);
    }

    public function test_every_invoice_produces_exactly_one_fk_lt_and_of_record(): void
    {
        $this->approvedInvoice();
        $this->approvedInvoice([
            'customer' => ['name' => 'PT Bank Artha Nusantara', 'npwp' => '01.345.678.9-091.000'],
            'faktur' => '010.000-26.00000002',
        ]);

        $csv = $this->exports->eFaktur(2026, 3)['csv'];

        foreach (['FK' => 2, 'LT' => 2, 'OF' => 2] as $type => $expected) {
            // -1 for the header block, which names all three record shapes.
            $this->assertSame(
                $expected,
                count($this->recordsOfType($csv, $type)) - 1,
                "expected {$expected} {$type} records",
            );
        }
    }

    public function test_an_invoice_without_a_faktur_number_is_reported_not_dropped(): void
    {
        $this->approvedInvoice(['faktur' => null]);

        $export = $this->exports->eFaktur(2026, 3);

        $this->assertSame(0, $export['summary']['exported']);
        $this->assertSame(1, $export['summary']['blocked']);
        $this->assertStringContainsString('Nomor faktur pajak belum diisi', $export['blockers'][0]['reason']);
    }

    public function test_a_customer_without_an_npwp_is_reported(): void
    {
        $this->approvedInvoice(['customer' => ['npwp' => null]]);

        $export = $this->exports->eFaktur(2026, 3);

        $this->assertSame(0, $export['summary']['exported']);
        $this->assertStringContainsString('NPWP pelanggan', $export['blockers'][0]['reason']);
    }

    public function test_only_approved_invoices_of_the_requested_period_appear(): void
    {
        $this->approvedInvoice();                                        // March
        $this->approvedInvoice(['invoice' => ['invoice_date' => '2026-04-02'], 'faktur' => '010.000-26.00000009']);

        $this->assertSame(1, $this->exports->eFaktur(2026, 3)['summary']['exported']);
        $this->assertSame(1, $this->exports->eFaktur(2026, 4)['summary']['exported']);
        $this->assertSame(0, $this->exports->eFaktur(2026, 5)['summary']['exported']);
    }

    /**
     * A comma inside a customer name would shift every column after it, so it is
     * stripped rather than passed through.
     */
    public function test_a_comma_in_a_name_cannot_shift_the_columns(): void
    {
        $this->approvedInvoice(['customer' => ['name' => 'PT Graha, Sentosa']]);

        $fk = $this->recordOfType($this->exports->eFaktur(2026, 3)['csv'], 'FK');

        $this->assertSame('PT Graha Sentosa', $fk[8]);
        $this->assertCount(20, $fk, 'the FK record must keep its 20 fields');
    }

    // ------------------------------------------------------------- e-Bupot

    public function test_a_bill_with_withholding_produces_a_bukti_potong(): void
    {
        $this->billWithholding();

        $export = $this->exports->eBupot(2026, 3);

        $this->assertSame(1, $export['summary']['exported']);
        $this->assertSame(100_000_000.0, $export['summary']['dpp']);
        $this->assertSame(2_650_000.0, $export['summary']['pph']);      // 100jt x 2,65%
        $this->assertSame('BP-202603-0001', $export['rows'][0]['slip_no']);
        $this->assertSame('28-403-01', $export['rows'][0]['object_code']);
    }

    /**
     * The rate printed is the one actually withheld, derived from the amounts on
     * the bill — not the master rate, which may have been revised since.
     */
    public function test_the_rate_reported_is_the_rate_actually_withheld(): void
    {
        $bill = $this->billWithholding();

        // The statutory rate changes afterwards; the historic slip must not move.
        Tax::query()->where('code', 'PPH4A2-PELAKSANAAN-BERSERTIFIKAT')->update(['rate' => 9.9]);

        $row = $this->exports->eBupot(2026, 3)['rows'][0];

        $this->assertSame(2.65, $row['rate']);                          // 2.650.000 / 100.000.000
        $this->assertSame(2_650_000.0, $row['pph']);
        $this->assertSame($bill->code, $row['document']);
    }

    public function test_a_tax_without_an_object_code_is_reported_rather_than_guessed(): void
    {
        $this->billWithholding(objectCode: null);

        $export = $this->exports->eBupot(2026, 3);

        $this->assertSame(0, $export['summary']['exported']);
        $this->assertStringContainsString('Kode objek pajak', $export['blockers'][0]['reason']);
    }

    public function test_a_vendor_without_an_npwp_is_reported(): void
    {
        $this->billWithholding(vendorNpwp: null);

        $export = $this->exports->eBupot(2026, 3);

        $this->assertSame(0, $export['summary']['exported']);
        $this->assertStringContainsString('NPWP vendor', $export['blockers'][0]['reason']);
    }

    public function test_bills_without_withholding_are_not_in_the_bupot_file(): void
    {
        $vendor = $this->makeVendor(['npwp' => '01.334.556.7-007.000']);

        $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'description' => 'Material tanpa potongan PPh',
            'dpp' => 50_000_000,
            'bill_date' => '2026-03-20',
            'vendor_invoice_no' => 'INV-1',
        ]));

        $this->assertSame(0, $this->exports->eBupot(2026, 3)['summary']['exported']);
    }

    public function test_slip_numbers_are_sequential_within_the_period(): void
    {
        $this->billWithholding();
        $this->billWithholding(vendorNpwp: '02.445.667.8-407.000');

        $rows = $this->exports->eBupot(2026, 3)['rows'];

        $this->assertSame(['BP-202603-0001', 'BP-202603-0002'], array_column($rows, 'slip_no'));
    }

    /**
     * The number belongs to the certificate, not to the run that printed it, so
     * it is minted when the bill is approved and stored on the bill.
     */
    public function test_the_bukti_potong_number_is_minted_at_approval_and_stored_on_the_bill(): void
    {
        $bill = $this->billWithholding();

        $this->assertSame('BP-202603-0001', $bill->fresh()->bupot_no);
        $this->assertSame('BP-202603-0001', $this->exports->eBupot(2026, 3)['rows'][0]['slip_no']);
    }

    /**
     * The defect this replaces, run end to end.
     *
     * Masa Maret is exported and the slips are handed to the vendors. The tax
     * officer then does exactly what the blockers card instructs — keys in the
     * missing NPWP — and re-runs. Under the old positional numbering
     * (`++$sequence` over the unblocked rows) the newly unblocked bill took
     * BP-202603-0002 from the vendor who already held it and pushed that
     * vendor's slip to -0003: one number, two taxpayers, two amounts. The
     * vendor cites the number when claiming its PPh credit, so the correction
     * is a pembetulan SPT, not a re-export.
     */
    public function test_re_running_a_masa_after_unblocking_a_bill_does_not_renumber_the_slips_already_issued(): void
    {
        $first = $this->billWithholding();
        $blocked = $this->billWithholding(vendorNpwp: null);
        $third = $this->billWithholding(vendorNpwp: '02.445.667.8-407.000');

        $runOne = $this->exports->eBupot(2026, 3);
        $this->assertSame(2, $runOne['summary']['exported']);
        $this->assertSame(1, $runOne['summary']['blocked']);

        $issued = array_column($runOne['rows'], 'slip_no', 'document');
        $this->assertSame(['BP-202603-0001', 'BP-202603-0003'], array_values($issued));

        // The blocker is cleared the only way the screen offers.
        $blocked->vendor->forceFill(['npwp' => '01.556.778.9-073.000'])->save();

        $runTwo = $this->exports->eBupot(2026, 3);
        $reissued = array_column($runTwo['rows'], 'slip_no', 'document');

        $this->assertSame(3, $runTwo['summary']['exported']);
        $this->assertSame($issued[$first->code], $reissued[$first->code]);
        $this->assertSame($issued[$third->code], $reissued[$third->code]);
        // The bill that was blocked keeps the number it was given at approval,
        // which is one nobody else ever held.
        $this->assertSame('BP-202603-0002', $reissued[$blocked->code]);
        $this->assertCount(3, array_unique($reissued));
    }

    /**
     * A cancelled bill's number stays spent. Reissuing it would put a second
     * vendor's withholding under a number a bukti potong already carries.
     */
    public function test_a_cancelled_bill_keeps_its_number_reserved(): void
    {
        $cancelled = $this->billWithholding();
        $this->apBills()->cancel($cancelled, $this->financeApprover(), 'Opname salah nilai.');

        $replacement = $this->billWithholding(vendorNpwp: '02.445.667.8-407.000');

        $this->assertSame('BP-202603-0001', $cancelled->fresh()->bupot_no);
        $this->assertSame('BP-202603-0002', $replacement->fresh()->bupot_no);
        $this->assertSame(['BP-202603-0002'], array_column($this->exports->eBupot(2026, 3)['rows'], 'slip_no'));
    }

    /** Each masa counts from 1 — the number embeds the masa it belongs to. */
    public function test_the_counter_restarts_with_each_masa(): void
    {
        $this->billWithholding();
        $april = $this->billWithholding(vendorNpwp: '02.445.667.8-407.000');
        $april->forceFill(['bill_date' => '2026-04-20', 'bupot_no' => null])->save();
        $this->apBills()->cancel($april, $this->financeApprover(), 'Ulang di masa yang benar.');

        $reissued = $this->billWithholding(billDate: '2026-04-20', vendorNpwp: '01.556.778.9-073.000');

        $this->assertSame('BP-202603-0001', $this->exports->eBupot(2026, 3)['rows'][0]['slip_no']);
        $this->assertSame('BP-202604-0001', $reissued->fresh()->bupot_no);
    }

    /**
     * Deleting the tax master row used to turn every historic withholding that
     * named it into "Jenis PPh belum ditetapkan — pilih jenis pajaknya pada
     * tagihan", an instruction that is impossible on an approved bill. The
     * delete is now refused (see TaxMasterDeletionTest); the export is hardened
     * against one that slipped through anyway, because the withholding was made
     * under that scheme and is reported under it.
     */
    public function test_a_soft_deleted_tax_row_still_exports_its_historic_bukti_potong(): void
    {
        $bill = $this->billWithholding();

        Tax::query()->whereKey($bill->pph_tax_id)->delete();

        $export = $this->exports->eBupot(2026, 3);

        $this->assertSame(1, $export['summary']['exported']);
        $this->assertSame(0, $export['summary']['blocked']);
        $this->assertSame('28-403-01', $export['rows'][0]['object_code']);
        $this->assertSame(2_650_000.0, $export['rows'][0]['pph']);
    }

    // -------------------------------------------------- numbering is not a GET

    /**
     * A read-only report may not assign legal reference numbers.
     *
     * buktiPotongNumber() used to ALLOCATE one and write fin_ap_bills.bupot_no
     * from inside the export — a GET gated on fin.view, and one that
     * PeriodCloseService::itemTaxExportReady() runs every time a closer merely
     * LOOKS at the checklist. Previewing the report therefore minted a
     * permanent bukti potong number, spent a counter from the BP-YYYYMM
     * sequence and bound both to a bill. The population it fired for is the one
     * approved before migration 2026_08_02_001112 added the column; that bill is
     * now reported as a blocker instead.
     */
    public function test_the_export_reports_an_unnumbered_bill_instead_of_minting_a_number_for_it(): void
    {
        $legacy = $this->billWithholding();
        // Approved before the column existed.
        $legacy->forceFill(['bupot_no' => null])->save();

        $spent = NumberSequence::query()->where('type', 'BP-202603')->value('last_number');

        $export = $this->exports->eBupot(2026, 3);

        $this->assertSame(0, $export['summary']['exported']);
        $this->assertSame(1, $export['summary']['blocked']);
        $this->assertStringContainsString('Nomor bukti potong', $export['blockers'][0]['reason']);

        // Nothing was written: not the bill, not the counter.
        $this->assertNull($legacy->fresh()->bupot_no);
        $this->assertSame($spent, NumberSequence::query()->where('type', 'BP-202603')->value('last_number'));
    }

    /**
     * The works-pair: issuing the numbers is its own act, performed once per
     * masa by someone who may approve. BP-202603-0001 was spent when the bill
     * was approved, so the catch-up takes the next unused number rather than
     * one a certificate may already carry.
     */
    public function test_issuing_the_numbers_for_a_masa_unblocks_the_export(): void
    {
        $legacy = $this->billWithholding();
        $legacy->forceFill(['bupot_no' => null])->save();

        $result = $this->exports->issueBuktiPotongNumbers(2026, 3);

        $this->assertSame(1, $result['summary']['issued']);
        $this->assertSame(0, $result['summary']['already_numbered']);
        $this->assertSame('BP-202603-0002', $legacy->fresh()->bupot_no);

        $export = $this->exports->eBupot(2026, 3);

        $this->assertSame(1, $export['summary']['exported']);
        $this->assertSame(0, $export['summary']['blocked']);
        $this->assertSame('BP-202603-0002', $export['rows'][0]['slip_no']);
    }

    /** Twice is once: a number already issued is never re-issued or replaced. */
    public function test_issuing_the_numbers_twice_leaves_every_number_as_it_was(): void
    {
        $legacy = $this->billWithholding();
        $legacy->forceFill(['bupot_no' => null])->save();
        $keeps = $this->billWithholding(vendorNpwp: '02.445.667.8-407.000');

        $this->exports->issueBuktiPotongNumbers(2026, 3);
        $issued = $legacy->fresh()->bupot_no;

        $again = $this->exports->issueBuktiPotongNumbers(2026, 3);

        $this->assertSame(0, $again['summary']['issued']);
        $this->assertSame(2, $again['summary']['already_numbered']);
        $this->assertSame($issued, $legacy->fresh()->bupot_no);
        $this->assertSame('BP-202603-0002', $keeps->fresh()->bupot_no);
    }

    /** The same thing over HTTP, on the permission the report is really gated on. */
    public function test_previewing_the_e_bupot_export_over_http_writes_nothing(): void
    {
        $legacy = $this->billWithholding();
        $legacy->forceFill(['bupot_no' => null])->save();

        $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/tax-exports/e-bupot?year=2026&month=3')
            ->assertOk()
            ->assertJsonPath('data.summary.exported', 0)
            ->assertJsonPath('data.summary.blocked', 1);

        $this->assertNull($legacy->fresh()->bupot_no);
    }

    public function test_issuing_the_numbers_requires_fin_approve(): void
    {
        $legacy = $this->billWithholding();
        $legacy->forceFill(['bupot_no' => null])->save();

        // fin.view opens the report; it does not issue certificates.
        $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->postJson('/api/finance/tax-exports/e-bupot/numbers', ['year' => 2026, 'month' => 3])
            ->assertForbidden();

        $this->assertNull($legacy->fresh()->bupot_no);

        $this->actingAs($this->userWith(['fin.view', 'fin.approve']), 'sanctum')
            ->postJson('/api/finance/tax-exports/e-bupot/numbers', ['year' => 2026, 'month' => 3])
            ->assertOk()
            ->assertJsonPath('data.summary.issued', 1)
            ->assertJsonPath('message', '1 nomor bukti potong diterbitkan untuk masa Maret 2026.');

        $this->assertSame('BP-202603-0002', $legacy->fresh()->bupot_no);
    }

    // ---------------------------------------------------------------- misc

    public function test_an_invalid_period_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->exports->eFaktur(2026, 13);
    }

    public function test_the_overview_returns_both_exports_for_one_period(): void
    {
        $this->approvedInvoice();
        $this->billWithholding();

        $overview = $this->exports->overview(2026, 3);

        $this->assertSame('e-faktur', $overview['efaktur']['kind']);
        $this->assertSame('e-bupot', $overview['ebupot']['kind']);
        $this->assertSame(1, $overview['efaktur']['summary']['exported']);
        $this->assertSame(1, $overview['ebupot']['summary']['exported']);
    }

    // ------------------------------------------------------------- helpers

    private function billWithholding(
        ?string $objectCode = '28-403-01',
        ?string $vendorNpwp = '01.334.556.7-007.000',
        string $billDate = '2026-03-20',
    ): ApBill {
        // One tax row per test, however many bills reference it — the code is unique.
        $tax = Tax::query()->where('code', Tax::pphFinalCodeForScheme('pelaksanaan_bersertifikat'))->first()
            ?? $this->makePphFinalTax();
        $tax->forceFill(['object_code' => $objectCode])->save();

        $vendor = $this->makeVendor(['npwp' => $vendorNpwp, 'is_subcontractor' => true]);

        return $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'description' => 'Opname subkon struktur',
            'dpp' => 100_000_000,
            'pph_tax_id' => $tax->id,
            'pph_amount' => 2_650_000,          // 100jt x 2,65%
            'bill_date' => $billDate,
            'vendor_invoice_no' => 'INV-SUB-1',
        ]));
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Petugas Pajak',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return list<string> the first record of the given type, split into fields */
    private function recordOfType(string $csv, string $type): array
    {
        $records = $this->recordsOfType($csv, $type);

        return str_getcsv($records[1]);   // [0] is the header row of that shape
    }

    /** @return list<string> */
    private function recordsOfType(string $csv, string $type): array
    {
        return array_values(array_filter(
            explode("\n", trim($csv)),
            fn (string $line): bool => str_starts_with($line, $type.','),
        ));
    }
}
