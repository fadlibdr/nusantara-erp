<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Tax;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The two tax fields on a vendor bill, and the three things that have to be
 * true about them before the bill reaches the ledger:
 *
 *   PPN is only creditable input VAT when the vendor can issue a faktur pajak;
 *   a withheld PPh has to name the article it was withheld under, because the
 *       liability account is read off that row and there are three of them;
 *   a bill raised from a purchase order keeps the withholding the operator
 *       entered — it used to be discarded on the way in.
 *
 * All three cost money in the same direction: the vendor is paid more than he
 * is owed, or the state is paid the wrong amount into the wrong account.
 */
class BillTaxFieldsTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    // ---------------------------------------------------- PPN and the PKP rule

    /**
     * A non-PKP vendor cannot issue a faktur pajak, so there is no input VAT to
     * credit. Booking it anyway put Rp 11.000.000 of a receivable from DJP on
     * the balance sheet that can never be recovered AND raised the payable to
     * Rp 111.000.000, so the company transfers the vendor money he may not
     * collect. PoService and SubcontractService have enforced this rule all
     * along; only the manual bill was missing it.
     */
    public function test_a_bill_that_charges_ppn_for_a_non_pkp_vendor_is_refused(): void
    {
        $vendor = $this->makeVendor(['name' => 'CV Karya Sipil Sejahtera', 'is_pkp' => false]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('bukan PKP');

        try {
            $this->apBills()->create([
                'vendor_id' => $vendor->id,
                'bill_date' => '2026-03-10',
                'description' => 'Pekerjaan sipil',
                'dpp' => 100000000,
                'ppn_amount' => 11000000,
            ]);
        } finally {
            $this->assertDatabaseCount('fin_ap_bills', 0);
        }
    }

    public function test_the_same_bill_from_a_pkp_vendor_books_its_input_vat(): void
    {
        $vendor = $this->makeVendor(['name' => 'PT Elektrindo Supply', 'is_pkp' => true]);

        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Panel listrik',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
            'faktur_pajak_no' => '010.002-26.00012345',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(11000000.0, $lines['1-1600']['debit']);
        $this->assertSame(111000000.0, $lines['2-1100']['credit']);
    }

    /**
     * A draft cannot be walked into the same state through the edit screen
     * either — recalc() runs on every save, not only on create.
     */
    public function test_editing_ppn_onto_a_non_pkp_vendors_draft_is_refused(): void
    {
        $vendor = $this->makeVendor(['name' => 'CV Karya Sipil Sejahtera', 'is_pkp' => false]);

        $bill = $this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Pekerjaan sipil',
            'dpp' => 100000000,
        ]);

        try {
            $this->apBills()->update($bill, ['ppn_amount' => 11000000]);
            $this->fail('PPN for a non-PKP vendor should be refused on edit too.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('bukan PKP', $e->getMessage());
            $this->assertSame(0.0, (float) $bill->fresh()->ppn_amount);
            $this->assertSame(100000000.0, (float) $bill->fresh()->total_payable);
        }
    }

    // ------------------------------------------- naming the withholding article

    /**
     * pph_tax_id and pph_amount used to be independently optional, and
     * pphLiabilityAccountCode() closed with a bare `return '2-1220'`. A subcon
     * opname keyed with Rp 25.837.500 of PPh final Pasal 4(2) and the "Jenis
     * PPh" lookup left blank therefore credited Hutang PPh 23: the PPh 23 SSP
     * for that month came out Rp 25.837.500 too high and the PPh final one that
     * much too low, and the bill could never be repaired because the e-Bupot
     * blocker names an edit an approved bill refuses.
     */
    public function test_a_withheld_amount_with_no_tax_named_is_refused(): void
    {
        $vendor = $this->makeVendor();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('harus menyebut jenis PPh-nya');

        try {
            $this->apBills()->create([
                'vendor_id' => $vendor->id,
                'bill_date' => '2026-03-10',
                'description' => 'Opname subkon struktur',
                'dpp' => 975000000,
                'pph_amount' => 25837500,       // 975jt x 2,65%
            ]);
        } finally {
            $this->assertDatabaseCount('fin_ap_bills', 0);
        }
    }

    public function test_the_same_amount_with_its_article_named_lands_in_the_right_liability(): void
    {
        $vendor = $this->makeVendor();

        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Opname subkon struktur',
            'dpp' => 975000000,
            'pph_tax_id' => $this->makePphFinalTax()->id,
            'pph_amount' => 25837500,
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(25837500.0, $lines['2-1230']['credit']);
        $this->assertArrayNotHasKey('2-1220', $lines);
        // 975.000.000 - 25.837.500 = 949.162.500
        $this->assertSame(949162500.0, $lines['2-1100']['credit']);
    }

    /**
     * Rule::exists('fin_taxes','id') does not exclude soft-deleted rows, so a
     * stale id from a cached lookup list would otherwise create a bill whose
     * withholding has no resolvable liability account.
     */
    public function test_a_soft_deleted_tax_cannot_be_named_on_a_new_bill(): void
    {
        $vendor = $this->makeVendor();
        $tax = $this->makePphFinalTax();
        $tax->delete();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sudah dihapus dari master pajak');

        $this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Opname subkon struktur',
            'dpp' => 975000000,
            'pph_tax_id' => $tax->id,
            'pph_amount' => 25837500,
        ]);
    }

    /** PPN is not a withholding; naming it as one would credit 2-1300. */
    public function test_naming_a_ppn_row_as_the_withholding_is_refused(): void
    {
        $vendor = $this->makeVendor();
        $ppn = Tax::create([
            'code' => 'PPN',
            'name' => 'PPN',
            'rate' => 11.0,
            'tax_type' => 'ppn',
            'coa_account_id' => $this->accountId('2-1300'),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('bukan jenis PPh dipotong');

        $this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Jasa konsultan',
            'dpp' => 100000000,
            'pph_tax_id' => $ppn->id,
            'pph_amount' => 2000000,
        ]);
    }

    // ------------------------------------------------ withholding from a PO bill

    /**
     * createFromPo() hard-set pph_tax_id = null / pph_amount = 0 on the
     * assumption that every PO buys goods, while the request validated both
     * fields and the form collected them. A services PO of Rp 115.600.000 with
     * "PPh 23 Jasa" picked was therefore billed at Rp 128.316.000 instead of
     * Rp 126.004.000: Rp 2.312.000 of statutory withholding handed to the vendor,
     * 2-1220 never credited, no bukti potong for the masa.
     */
    public function test_a_services_po_bill_keeps_the_withholding_the_operator_entered(): void
    {
        $vendor = $this->makeVendor();
        $po = $this->makePurchaseOrder($vendor, [
            'subtotal' => 115600000,
            'dpp' => 115600000,
            'ppn_amount' => 12716000,
            'total' => 128316000,
        ]);

        $bill = $this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-10',
            'pph_tax_id' => $this->makePph23Tax(2.0)->id,
            'pph_amount' => 2312000,            // 115.600.000 x 2%
        ]);

        // 115.600.000 + 12.716.000 - 2.312.000 = 126.004.000
        $this->assertSame(2312000.0, (float) $bill->pph_amount);
        $this->assertSame(126004000.0, (float) $bill->total_payable);
    }

    /**
     * And the hint the form gives — "Kosongkan untuk menghitung dari tarif
     * pajak yang dipilih" — is honoured on this path too.
     */
    public function test_a_po_bill_derives_the_withholding_from_the_named_tax_when_the_amount_is_blank(): void
    {
        $vendor = $this->makeVendor();
        $po = $this->makePurchaseOrder($vendor, [
            'subtotal' => 115600000,
            'dpp' => 115600000,
            'ppn_amount' => 12716000,
            'total' => 128316000,
        ]);

        $bill = $this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-10',
            'pph_tax_id' => $this->makePph23Tax(2.0)->id,
        ]);

        $this->assertSame(2312000.0, (float) $bill->pph_amount);
        $this->assertSame(126004000.0, (float) $bill->total_payable);
    }

    /**
     * A final bill priced net of an uang muka still withholds on the WHOLE
     * order: PPh is charged on the jumlah bruto of the service and the advance
     * withheld nothing. PO Rp 100.000.000 with a Rp 30.000.000 DP leaves a
     * Rp 70.000.000 final bill, and 2 % of the order is Rp 2.000.000 — not
     * Rp 1.400.000.
     */
    public function test_the_derived_rate_on_a_final_bill_is_charged_on_the_whole_order(): void
    {
        $vendor = $this->makeVendor();
        $po = $this->makePurchaseOrder($vendor);

        $this->approveBill($this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-01',
            'is_advance' => true,
            'dpp' => 30000000,
        ]));

        $final = $this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-10',
            'pph_tax_id' => $this->makePph23Tax(2.0)->id,
        ]);

        $this->assertSame(70000000.0, (float) $final->dpp);
        $this->assertSame(2000000.0, (float) $final->pph_amount);
    }

    /**
     * An uang muka buys no work yet and books an asset rather than a cost, so it
     * withholds nothing; the final bill carries the whole withholding for the
     * order. Refused rather than silently dropped, which is what the old code
     * did to every PO bill.
     */
    public function test_withholding_on_an_uang_muka_is_refused_rather_than_dropped(): void
    {
        $vendor = $this->makeVendor();
        $po = $this->makePurchaseOrder($vendor);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak memotong PPh');

        try {
            $this->apBills()->createFromPo($po, [
                'bill_date' => '2026-03-01',
                'is_advance' => true,
                'dpp' => 30000000,
                'pph_tax_id' => $this->makePph23Tax(2.0)->id,
                'pph_amount' => 600000,
            ]);
        } finally {
            $this->assertDatabaseCount('fin_ap_bills', 0);
        }
    }

    /**
     * The repair path the blockers card assumes: pick the tax on the draft and
     * leave the amount blank. It used to store the tax and withhold nothing —
     * a bill that NAMED PPh 23 and still paid it to the vendor.
     */
    public function test_naming_the_tax_on_an_edit_derives_the_amount_the_hint_promises(): void
    {
        $vendor = $this->makeVendor();
        $bill = $this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Jasa cleaning service',
            'dpp' => 115600000,
        ]);

        $updated = $this->apBills()->update($bill, [
            'pph_tax_id' => $this->makePph23Tax(2.0)->id,
        ]);

        $this->assertSame(2312000.0, (float) $updated->pph_amount);
        $this->assertSame(113288000.0, (float) $updated->total_payable);
    }

    /** An amount the operator states is never overwritten by the master rate. */
    public function test_an_explicitly_stated_amount_survives_the_edit(): void
    {
        $vendor = $this->makeVendor();
        $bill = $this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Jasa cleaning service',
            'dpp' => 115600000,
        ]);

        $updated = $this->apBills()->update($bill, [
            'pph_tax_id' => $this->makePph23Tax(2.0)->id,
            'pph_amount' => 2000000,
        ]);

        $this->assertSame(2000000.0, (float) $updated->pph_amount);
    }

    /**
     * A draft keyed before these guards existed can still be sitting there, so
     * approval re-asserts them: this is the last moment before the withholding
     * lands in a liability account no edit can move it out of.
     */
    public function test_a_legacy_draft_with_an_unnamed_withholding_cannot_be_approved(): void
    {
        $vendor = $this->makeVendor();
        $bill = $this->apBills()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Opname subkon struktur',
            'dpp' => 975000000,
        ]);

        // Straight to the column, exactly as a row written before the guard.
        $bill->forceFill(['pph_amount' => 25837500, 'total_payable' => 949162500])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('harus menyebut jenis PPh-nya');

        try {
            $this->approveBill($bill);
        } finally {
            $this->assertSame(DocumentStatus::Submitted, ApBill::query()->find($bill->id)->status);
            $this->assertSame(0, $this->journalCountFor('ap_bill', (int) $bill->id));
        }
    }

    // --------------------------------------------------------------- helpers

    private function journalCountFor(string $type, int $id): int
    {
        return Journal::query()
            ->where('reference_type', $type)
            ->where('reference_id', $id)
            ->count();
    }
}
