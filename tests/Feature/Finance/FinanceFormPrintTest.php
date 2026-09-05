<?php

namespace Tests\Feature\Finance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Http\Resources\PaymentAllocationResource;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PaymentWithholding;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Models\TaxObligation;
use Modules\Finance\Services\FinanceFormService;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * Formulir rumah untuk modul Keuangan — lembar verifikasi tagihan, bukti
 * pembayaran, voucher jurnal, register kewajiban pajak.
 *
 * Declared in Modules\Core\Support\PrintableDocuments and rendered by the
 * generic sheet, so what is worth testing per document is not "does it render"
 * but WHICH CELLS ARE FILLED AND WHICH ARE RULED. Every one of these four is
 * signed by somebody who releases money against it, and the declarative path
 * puts a plausible default one keystroke from an honest blank.
 *
 * THE THREE CELLS THIS FILE EXISTS FOR:
 *
 *   PENERIMA on a bukti pembayaran. fin_payments has no recipient column at
 *   all; the name is derivable only from the documents the payment settles.
 *   A payroll or SSP payment settles a GL ACCOUNT, so the recipient stays
 *   unknown and the rule stays blank — printing "2-1110 Hutang Gaji" as the
 *   penerima would name an account as the person who took the cash.
 *
 *   The DEBIT/KREDIT footing of a voucher jurnal. The generic sheet prints a
 *   totals row in the LAST column only, so a debit total under a KREDIT
 *   heading is exactly the mislabelled figure a voucher may not carry. The
 *   footing is therefore its own two-column recap table, selisih included.
 *
 *   NILAI on a masa row of the kalender pajak. fin_tax_obligations.amount is
 *   NULLABLE by design — the calendar is generated ahead of the money — so an
 *   unpriced masa is ruled, and the register total says how many masas it was
 *   able to count.
 */
class FinanceFormPrintTest extends ErpTestCase
{
    /** Every day count and every "as at" below is read from this day. */
    private const TODAY = '2026-08-09';

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------- fixtures

    private function vendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'code' => 'VND-0031',
            'name' => 'CV Sinar Baja Perkasa',
            'legal_name' => 'CV Sinar Baja Perkasa',
            'npwp' => '02.345.678.9-023.000',
            'is_pkp' => true,
            'classification' => 'material',
            'address' => 'Jl. Industri Raya No. 17, Bekasi',
            'city' => 'Bekasi',
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    /**
     * A bill whose arithmetic can be checked by hand:
     * 200.000.000 DPP + 22.000.000 PPN − 4.000.000 PPh − 10.000.000 retensi
     * = 208.000.000 total payable.
     */
    private function bill(array $attributes = []): ApBill
    {
        return ApBill::query()->create(array_merge([
            'code' => 'BIL/2026/VIII/0012',
            'vendor_id' => $this->vendor()->id,
            'bill_date' => '2026-08-03',
            'due_date' => '2026-09-02',
            'description' => 'Pengadaan besi beton ulir D16 untuk pekerjaan struktur lantai 3',
            'dpp' => 200_000_000,
            'ppn_amount' => 22_000_000,
            'pph_amount' => 4_000_000,
            'retention_amount' => 10_000_000,
            'total_payable' => 208_000_000,
            'amount_paid' => 58_000_000,
            'vendor_invoice_no' => 'INV/SBP/2026/0451',
            'faktur_pajak_no' => '010.000-26.00000123',
            'status' => 'approved',
        ], $attributes));
    }

    private function bankAccount(): BankAccount
    {
        $this->seedLedger(2026);

        return BankAccount::query()->firstOrCreate(['code' => 'BNK-001'], [
            'name' => 'BCA Operasional',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '5420123456',
            'account_name' => 'PT Nusantara Karya Integrasi',
            'coa_account_id' => Account::query()->where('code', '1-1210')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /** A vendor payment: one allocation against the bill above, no withholdings. */
    private function vendorPayment(): Payment
    {
        $bill = $this->bill();

        $payment = Payment::query()->create([
            'code' => 'PAY/2026/VIII/0021',
            'direction' => 'out',
            'payment_date' => '2026-08-07',
            'bank_account_id' => $this->bankAccount()->id,
            'amount' => 58_000_000,
            'reference' => 'TRF/BCA/20260807/0091',
            'notes' => 'Pembayaran termin pertama besi beton',
            'status' => 'posted',
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'payable_type' => PaymentAllocation::TYPE_AP_BILL,
            'payable_id' => $bill->id,
            'amount' => 58_000_000,
            'remark' => 'Pelunasan sebagian',
        ]);

        return $payment->fresh();
    }

    /**
     * A payroll payment: it settles a GL LIABILITY, so nothing in the database
     * knows who the money went to.
     */
    private function payrollPayment(): Payment
    {
        $this->seedLedger(2026);

        $account = Account::query()->where('code', '2-1110')->firstOrFail();

        $payment = Payment::query()->create([
            'code' => 'PAY/2026/VIII/0022',
            'direction' => 'out',
            'payment_date' => '2026-08-07',
            'bank_account_id' => $this->bankAccount()->id,
            'amount' => 91_500_000,
            'status' => 'posted',
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'payable_type' => PaymentAllocation::TYPE_GL_ACCOUNT,
            'payable_id' => $account->id,
            'amount' => 91_500_000,
            'remark' => 'Gaji Juli 2026',
        ]);

        return $payment->fresh();
    }

    /**
     * A customer receipt: Rp 100 juta settled, of which Rp 7 juta never reached
     * the bank — Rp 5,8 juta PPh final withheld and Rp 1,2 juta of denda.
     */
    private function customerReceipt(): Payment
    {
        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'billing_address' => 'Jl. Jenderal Sudirman Kav. 52-53, Jakarta Selatan',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $contract = Contract::query()->create([
            'code' => 'CTR/2026/I/0001',
            'customer_id' => $customer->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'scope_type' => 'construction',
            'value' => 1_000_000_000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 110_000_000,
            'total_with_ppn' => 1_110_000_000,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'status' => 'approved',
        ]);

        $invoice = ArInvoice::query()->create([
            'code' => 'INV/2026/VIII/0005',
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'description' => 'Termin 1 — uang muka 20%',
            'dpp' => 100_000_000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 11_000_000,
            'retention_withheld' => 0,
            'total' => 111_000_000,
            'amount_paid' => 0,
            'terbilang' => 'Seratus sebelas juta rupiah',
            'status' => 'approved',
        ]);

        $payment = Payment::query()->create([
            'code' => 'RCV/2026/VIII/0003',
            'direction' => 'in',
            'payment_date' => '2026-08-07',
            'bank_account_id' => $this->bankAccount()->id,
            'amount' => 93_000_000,
            'reference' => 'TRF/BCA/20260807/0112',
            'status' => 'posted',
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'payable_type' => PaymentAllocation::TYPE_AR_INVOICE,
            'payable_id' => $invoice->id,
            'amount' => 100_000_000,
        ]);

        PaymentWithholding::query()->create([
            'payment_id' => $payment->id,
            'ar_invoice_id' => $invoice->id,
            'type' => 'pph_final',
            'amount' => 5_800_000,
            'certificate_no' => 'BP/2026/VIII/0007',
            'certificate_date' => '2026-08-07',
        ]);

        PaymentWithholding::query()->create([
            'payment_id' => $payment->id,
            'ar_invoice_id' => $invoice->id,
            'type' => 'other_deduction',
            'amount' => 1_200_000,
            'reason' => 'Denda keterlambatan 4 hari sesuai pasal 12 kontrak',
        ]);

        return $payment->fresh();
    }

    /**
     * A replenishment: the transfer that puts back what the drawer spent.
     *
     * The fund gets its own 1-11xx leaf the way PettyCashFundService insists
     * on — one drawer, one postable account — because the column is unique.
     */
    private function pettyCashReplenishment(): Payment
    {
        $bankAccount = $this->bankAccount();

        $fund = PettyCashFund::query()->create([
            'code' => 'KK-GRAHA',
            'name' => 'Kas Kecil Proyek Graha Sentosa',
            'coa_account_id' => Account::query()->create([
                'code' => '1-1110',
                'name' => 'Kas Kecil Proyek Graha Sentosa',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_postable' => true,
                'is_active' => true,
                'parent_id' => Account::query()->where('code', '1-1100')->firstOrFail()->id,
            ])->id,
            'custodian_id' => $this->adminUser()->id,
            'float_amount' => 5_000_000,
        ]);

        $payment = Payment::query()->create([
            'code' => 'PAY/2026/VIII/0031',
            'direction' => 'out',
            'payment_date' => '2026-08-07',
            'bank_account_id' => $bankAccount->id,
            'petty_cash_fund_id' => $fund->id,
            'amount' => 1_200_000,
            'status' => 'posted',
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'payable_type' => PaymentAllocation::TYPE_PETTY_CASH_FUND,
            'payable_id' => $fund->id,
            'amount' => 1_200_000,
            'remark' => 'Pengisian kembali bon Juli',
        ]);

        return $payment->fresh();
    }

    private function journal(): Journal
    {
        $this->seedLedger(2026);

        $journal = Journal::query()->create([
            'code' => 'JV/2026/VIII/0004',
            'journal_date' => '2026-08-05',
            'description' => 'Penyesuaian beban sewa direksi keet bulan Agustus 2026',
            'status' => 'posted',
        ]);

        JournalLine::query()->create([
            'journal_id' => $journal->id,
            'account_id' => Account::query()->where('code', '6-4100')->firstOrFail()->id,
            'description' => 'Sewa direksi keet Agustus',
            'debit' => 12_500_000,
            'credit' => 0,
        ]);

        JournalLine::query()->create([
            'journal_id' => $journal->id,
            'account_id' => Account::query()->where('code', '1-1100')->firstOrFail()->id,
            'description' => 'Kas bank BCA',
            'debit' => 0,
            'credit' => 12_500_000,
        ]);

        return $journal->fresh();
    }

    /** Two masas of 2026 — one settled, one still open and still unpriced. */
    private function taxObligation(): TaxObligation
    {
        $settled = TaxObligation::query()->create([
            'tax_type' => 'pph21',
            'masa_year' => 2026,
            'masa_month' => 6,
            'name' => 'PPh 21 Masa Juni 2026',
            'due_date' => '2026-07-10',
            'amount' => 14_750_000,
            'ntpn' => '1234567890ABCDEF',
            'disetor_date' => '2026-07-08',
            'dilapor_date' => '2026-07-18',
        ]);

        TaxObligation::query()->create([
            'tax_type' => 'ppn',
            'masa_year' => 2026,
            'masa_month' => 7,
            'name' => 'PPN Masa Juli 2026',
            'due_date' => '2026-08-31',
        ]);

        // A different year, to prove the register is bounded by the masa year
        // of the row the button was pressed on.
        TaxObligation::query()->create([
            'tax_type' => 'pph23',
            'masa_year' => 2025,
            'masa_month' => 11,
            'name' => 'PPh 23 Masa November 2025',
            'due_date' => '2025-12-10',
            'amount' => 3_200_000,
        ]);

        return $settled->fresh();
    }

    // ------------------------------------------------------ tagihan vendor

    public function test_bill_verification_prints_the_vendor_and_its_invoice_references(): void
    {
        $html = $this->forms->html('tagihan-vendor', ['id' => $this->bill()->id]);

        $this->assertStringContainsString('LEMBAR VERIFIKASI TAGIHAN', $html);
        $this->assertStringContainsString('Form F/VT', $html);
        $this->assertStringContainsString('BIL/2026/VIII/0012', $html);
        $this->assertStringContainsString('CV Sinar Baja Perkasa', $html);
        $this->assertStringContainsString('02.345.678.9-023.000', $html);
        $this->assertStringContainsString('INV/SBP/2026/0451', $html);
        $this->assertStringContainsString('010.000-26.00000123', $html);
        // The vendor band, not the four-party project band: a supplier's bill
        // has no pemilik and no konsultan MK.
        $this->assertStringContainsString('PEMASOK / VENDOR', $html);
        $this->assertStringNotContainsString('KONSULTAN MK', $html);
    }

    public function test_bill_verification_foots_dpp_ppn_pph_and_the_netto(): void
    {
        $html = $this->forms->html('tagihan-vendor', ['id' => $this->bill()->id]);

        $this->assertStringContainsString('200.000.000,00', $html);   // DPP
        $this->assertStringContainsString('22.000.000,00', $html);    // PPN
        $this->assertStringContainsString('4.000.000,00', $html);     // PPh dipotong
        $this->assertStringContainsString('10.000.000,00', $html);    // retensi ditahan
        $this->assertStringContainsString('208.000.000,00', $html);   // netto dibayar
        $this->assertStringContainsString('150.000.000,00', $html);   // sisa terutang
        $this->assertStringContainsString('Dua ratus delapan juta rupiah', $html);
    }

    /**
     * fin_ap_bills.cost_category is NULLABLE and a null means "derive it from
     * the source document when the journal is posted" — a derivation that lives
     * in ApBillService and is private to it. The sheet quotes what was STATED
     * and rules what was not; a second derivation here could disagree with the
     * journal that actually carries the cost.
     */
    public function test_an_unstated_cost_category_is_ruled_not_derived(): void
    {
        $html = $this->forms->html('tagihan-vendor', ['id' => $this->bill()->id]);

        $this->assertStringContainsString('KATEGORI BIAYA', $html);
        $this->assertStringNotContainsString('Material', $html);
        $this->assertStringNotContainsString('Overhead', $html);
    }

    /**
     * A bill that arrived without its faktur pajak rules that line.
     *
     * On the CELL. This sheet rules KATEGORI BIAYA on the very fixture used
     * here (cost_category is null and stays null — see the test above) and the
     * Catatan block rules three more, so
     * assertStringContainsString('fill-line') is true of every copy of this
     * sheet: it stayed true with the line printing "Menyusul", a word that
     * tells the verifier a document exists which may never have been issued.
     */
    public function test_a_bill_without_a_tax_invoice_rules_that_line(): void
    {
        $html = $this->forms->html('tagihan-vendor', [
            'id' => $this->bill(['faktur_pajak_no' => null])->id,
        ]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('NO. FAKTUR PAJAK'), $html);
        $this->assertStringNotContainsString('010.000-26.00000123', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /** And a bill that DID come with one prints it on that same line. */
    public function test_a_bill_with_a_tax_invoice_prints_the_number_on_that_line(): void
    {
        $html = $this->forms->html('tagihan-vendor', ['id' => $this->bill()->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('NO. FAKTUR PAJAK', '010.000-26.00000123'),
            $html,
        );
    }

    // ----------------------------------------------------- bukti pembayaran

    public function test_a_vendor_payment_names_the_recipient_it_can_prove(): void
    {
        $html = $this->forms->html('bukti-pembayaran', ['id' => $this->vendorPayment()->id]);

        $this->assertStringContainsString('PAY/2026/VIII/0021', $html);
        $this->assertStringContainsString('CV Sinar Baja Perkasa', $html);
        $this->assertStringContainsString('Bank Central Asia', $html);
        $this->assertStringContainsString('5420123456', $html);
        $this->assertStringContainsString('TRF/BCA/20260807/0091', $html);
        $this->assertStringContainsString('BIL/2026/VIII/0012', $html);
        $this->assertStringContainsString('58.000.000,00', $html);
        $this->assertStringContainsString('Lima puluh delapan juta rupiah', $html);
        // Pengeluaran, said in the identity block rather than in the title:
        // fin_payments carries both directions and one sheet serves both.
        $this->assertStringContainsString('Pengeluaran', $html);
    }

    /**
     * The cell this document exists to leave blank. A payroll or SSP payment
     * settles a GL liability; nothing anywhere records who took the cash, and
     * an account name printed as the penerima would answer a question the
     * database cannot.
     */
    public function test_a_payment_settling_a_gl_account_rules_the_recipient(): void
    {
        $payment = $this->payrollPayment();

        // Asserted on the resolver rather than by hunting the rendered sheet
        // for an absence: the account NAME legitimately appears in the
        // allocation table below (that is what was settled, and it is a fact),
        // so "the string is missing" would be the wrong question. What must be
        // null is the answer to "who received this money".
        $this->assertNull(app(FinanceFormService::class)->paymentRecipient($payment));

        $html = $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        // On the CELL, not on the sheet. 'PENERIMA' is printed whether or not
        // the line has an answer and 'fill-line' is on any sheet carrying one
        // blank anywhere, so both held with the account name printed as the
        // recipient — which is the sheet this test exists to refuse.
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('PENERIMA'), $html);
        $this->assertStringContainsString('91.500.000,00', $html);
        $this->assertStringContainsString('2-1110', $html);
    }

    /**
     * The same cell on the other direction, where BOTH halves move at once.
     *
     * A receipt into a GL account — a staff advance handed back, a bank
     * interest credit — is the one voucher where the counterparty is unknowable
     * AND the caption inverts: nothing records who handed the cash over, and
     * "PENERIMA" over a receipt would say we took money we were given. The
     * label reads DITERIMA DARI and the rule under it stays blank.
     */
    public function test_a_receipt_settling_a_gl_account_inverts_the_caption_and_still_rules_it(): void
    {
        $this->seedLedger(2026);

        $payment = Payment::query()->create([
            'code' => 'RCV/2026/VIII/0009',
            'direction' => 'in',
            'payment_date' => '2026-08-07',
            'bank_account_id' => $this->bankAccount()->id,
            'amount' => 12_500_000,
            'status' => 'posted',
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'payable_type' => PaymentAllocation::TYPE_GL_ACCOUNT,
            'payable_id' => Account::query()->where('code', '2-1110')->firstOrFail()->id,
            'amount' => 12_500_000,
            'remark' => 'Setoran kembali sisa uang muka perjalanan dinas',
        ]);

        $html = $this->forms->html('bukti-pembayaran', ['id' => $payment->fresh()->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('DITERIMA DARI'), $html);
        // The caption MOVED; it did not appear twice.
        $this->assertStringNotContainsString('>PENERIMA<', $html);
        $this->assertStringContainsString('Penerimaan', $html);
        $this->assertStringContainsString('12.500.000,00', $html);
    }

    /**
     * One identity ROW whose value is a RULED BLANK — the label and the rule
     * together, in the markup that puts them in the same row of the block.
     * Either half alone is on every copy of this sheet.
     */
    private function ruledIdentityCell(string $label): string
    {
        return $this->identityCell($label, '<span class="fill-line"></span>');
    }

    /** The same row with a VALUE where the rule would be. */
    private function identityCell(string $label, string $value): string
    {
        return '~>'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*'
            .preg_quote($value, '~').'\s*</td>~';
    }

    public function test_a_receipt_prints_every_withholding_with_its_reason(): void
    {
        $html = $this->forms->html('bukti-pembayaran', ['id' => $this->customerReceipt()->id]);

        $this->assertStringContainsString('Penerimaan', $html);
        $this->assertStringContainsString('PT Graha Sentosa Propertindo', $html);
        $this->assertStringContainsString('PPh final dipotong pelanggan', $html);
        $this->assertStringContainsString('BP/2026/VIII/0007', $html);
        $this->assertStringContainsString('Denda keterlambatan 4 hari sesuai pasal 12 kontrak', $html);
        // 93.000.000 kas + 7.000.000 dipotong = 100.000.000 yang dilunasi.
        $this->assertStringContainsString('100.000.000,00', $html);
        $this->assertStringContainsString('93.000.000,00', $html);
    }

    // ------------------------------------------------------- voucher jurnal

    public function test_the_journal_voucher_prints_every_line_and_foots_both_sides(): void
    {
        $html = $this->forms->html('voucher-jurnal', ['id' => $this->journal()->id]);

        $this->assertStringContainsString('VOUCHER JURNAL', $html);
        $this->assertStringContainsString('JV/2026/VIII/0004', $html);
        $this->assertStringContainsString('Sewa direksi keet Agustus', $html);
        $this->assertStringContainsString('6-4100', $html);
        $this->assertStringContainsString('1-1100', $html);
        $this->assertStringContainsString('12.500.000,00', $html);
        // The footing is its own table so that a debit total is never printed
        // under a column headed KREDIT.
        $this->assertStringContainsString('Jumlah debit', $html);
        $this->assertStringContainsString('Jumlah kredit', $html);
        $this->assertStringContainsString('Selisih', $html);
    }

    /**
     * A voucher whose two sides differ has to SAY so on the paper. This is the
     * one figure on the sheet that a reader checks with a pen, and hiding an
     * out-of-balance draft behind a footing that only ever prints one side
     * would defeat the whole document.
     */
    public function test_an_unbalanced_journal_prints_its_difference(): void
    {
        $journal = $this->journal();
        $journal->lines()->orderByDesc('id')->first()->update(['credit' => 12_000_000]);

        $html = $this->forms->html('voucher-jurnal', ['id' => $journal->id]);

        $this->assertStringContainsString('500.000,00', $html);
    }

    // ----------------------------------------------------- kewajiban pajak

    public function test_the_tax_register_prints_the_whole_masa_year_of_the_row_it_was_printed_from(): void
    {
        $html = $this->forms->html('kewajiban-pajak', ['id' => $this->taxObligation()->id]);

        $this->assertStringContainsString('REGISTER KEWAJIBAN PAJAK MASA', $html);
        $this->assertStringContainsString('PPh 21 Masa Juni 2026', $html);
        $this->assertStringContainsString('PPN Masa Juli 2026', $html);
        $this->assertStringContainsString('1234567890ABCDEF', $html);
        $this->assertStringContainsString('Dilapor', $html);
        $this->assertStringContainsString('Belum disetor', $html);
        // The 2025 masa belongs to another register and another year's file.
        $this->assertStringNotContainsString('PPh 23 Masa November 2025', $html);
    }

    /**
     * fin_tax_obligations.amount is nullable because the calendar row is minted
     * before the money is known. A masa nobody has priced is ruled, and the
     * total says how many masas it was able to count rather than quietly
     * treating the unpriced ones as zero.
     */
    public function test_an_unpriced_masa_is_ruled_and_the_total_says_what_it_counted(): void
    {
        $html = $this->forms->html('kewajiban-pajak', ['id' => $this->taxObligation()->id]);

        $this->assertStringContainsString('14.750.000,00', $html);
        $this->assertStringContainsString('1 dari 2 masa', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * The Catatan block is RULED, not the anchor row's note.
     *
     * This sheet is a YEAR-WIDE register printed from whichever masa the button
     * happened to be pressed on. Reading notes off that row made the same
     * register carry a different Catatan depending on which line was clicked,
     * and printed that one row's note twice — once in the block, once in its
     * own KETERANGAN cell.
     */
    public function test_the_tax_register_does_not_carry_the_anchor_rows_note(): void
    {
        $anchor = $this->taxObligation();
        $anchor->forceFill(['notes' => 'CATATAN-MASA-JUNI'])->save();

        $html = $this->forms->html('kewajiban-pajak', ['id' => $anchor->id]);

        // Once, in its own KETERANGAN cell on the Juni row — never a second
        // time as the whole register's note.
        $this->assertSame(1, substr_count($html, 'CATATAN-MASA-JUNI'));
    }

    /**
     * An account retired since the entry was posted keeps its code and name on
     * the voucher.
     *
     * fin_accounts soft-deletes and a chart of accounts is tidied every year
     * end; the journals posted to those accounts stay for ever, and a voucher
     * jurnal is what an auditor is handed when he asks what an entry was. With
     * the relation loaded plainly the row printed a real 12.500.000,00 debit
     * against a ruled KODE AKUN and a ruled NAMA AKUN — a signed voucher moving
     * money to an account it will not name.
     */
    public function test_a_retired_account_keeps_its_name_on_the_voucher(): void
    {
        $journal = $this->journal();

        Account::query()->where('code', '6-4100')->firstOrFail()->delete();

        $html = $this->forms->html('voucher-jurnal', ['id' => $journal->id]);

        $this->assertStringContainsString('6-4100', $html);
        // 6-4100 as the chart of accounts names it, escaped as the sheet emits it.
        $this->assertStringContainsString('Beban Umum &amp; Administrasi', $html);
        $this->assertStringContainsString('12.500.000,00', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    // -------------------------------------------- arah bukti kas & bank

    /**
     * On a RECEIPT the counterparty did not receive the money — they handed it
     * over — and the sheet has to say so above the line they sign.
     *
     * The name was always right; the label was inverted. A filed voucher read
     * "PENERIMA : PT Graha Sentosa Propertindo" over their own payment to us,
     * and asked them to sign under "Diterima,". Both now turn on the direction
     * the row already stores.
     */
    public function test_a_receipt_names_the_payer_as_the_payer_not_the_recipient(): void
    {
        $html = $this->forms->html('bukti-pembayaran', ['id' => $this->customerReceipt()->id]);

        $this->assertStringContainsString('DITERIMA DARI', $html);
        $this->assertStringContainsString('Disetorkan oleh,', $html);
        $this->assertStringContainsString('Nama &amp; Jabatan Penyetor', $html);

        // The outgoing wording must not survive onto an incoming sheet.
        // Anchored on the cell, because the sheet's own title carries the word
        // PENERIMAAN and always will.
        $this->assertStringNotContainsString('>PENERIMA<', $html);
        $this->assertStringNotContainsString('Diterima,', $html);
    }

    /** And a payment keeps the wording it always had. */
    public function test_a_payment_still_names_the_counterparty_as_the_recipient(): void
    {
        $html = $this->forms->html('bukti-pembayaran', ['id' => $this->vendorPayment()->id]);

        $this->assertStringContainsString('>PENERIMA<', $html);
        $this->assertStringContainsString('Diterima,', $html);
        $this->assertStringContainsString('Nama &amp; Jabatan Penerima', $html);
        $this->assertStringNotContainsString('DITERIMA DARI', $html);
        $this->assertStringNotContainsString('Disetorkan oleh,', $html);
    }

    // ---------------------------------------- dokumen yang sudah dihapus

    /**
     * A settled document deleted afterwards keeps its number on the voucher.
     *
     * allocationTargets() looks the settled documents up with findMany(), and
     * fin_ap_bills soft-deletes — so a bill deleted after the payment posted
     * came back from findMany() NOT AS NULL BUT AS NOTHING AT ALL. The row
     * then fell through `$targets[...] ?? []` and printed a real
     * Rp 58.000.000,00 with no document number and no description beside it:
     * money on a signed voucher that names nothing.
     *
     * The allocation row itself is never dropped. What was settled is a fact
     * of the payment, not of the document's current lifecycle.
     */
    public function test_a_deleted_bill_keeps_its_number_on_the_voucher_that_paid_it(): void
    {
        $payment = $this->vendorPayment();
        $bill = ApBill::query()->firstOrFail();

        $bill->delete();

        $html = $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        $this->assertStringContainsString('BIL/2026/VIII/0012', $html);
        $this->assertStringContainsString('Pengadaan besi beton ulir D16', $html);
        $this->assertStringContainsString('CV Sinar Baja Perkasa', $html);
        $this->assertStringContainsString('58.000.000,00', $html);
    }

    /** The same on a receipt whose invoice has since been deleted. */
    public function test_a_deleted_invoice_keeps_its_number_on_the_receipt(): void
    {
        $payment = $this->customerReceipt();

        ArInvoice::query()->firstOrFail()->delete();

        $html = $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        $this->assertStringContainsString('100.000.000,00', $html);
        $this->assertStringContainsString('PT Graha Sentosa Propertindo', $html);
    }

    /**
     * And on a GL allocation, where the account is what was settled.
     *
     * The PENERIMA line stays ruled either way — an account is not a person —
     * but the allocation table must still say WHICH account took the money.
     */
    public function test_a_deleted_account_keeps_its_code_on_the_voucher(): void
    {
        $payment = $this->payrollPayment();
        $account = Account::query()->where('code', '2-1110')->firstOrFail();

        $account->delete();

        $html = $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        $this->assertStringContainsString('2-1110', $html);
        $this->assertStringContainsString('91.500.000,00', $html);
    }

    /**
     * A vendor archived since the bill arrived keeps its name on the sheet
     * that releases the money.
     *
     * The whole band of a lembar verifikasi IS the vendor — PEMASOK / VENDOR,
     * NPWP, the address the cheque is sent to — and the sheet is signed by
     * three people to authorise Rp 208.000.000,00. prc_vendors soft-deletes on
     * the ordinary path: a supplier stops trading and the master row is
     * archived, while the bills raised against it stay for ever. Loaded
     * plainly the band came back ruled, and a verifier is asked to approve a
     * payment to nobody.
     */
    public function test_an_archived_vendor_keeps_its_name_on_the_bill_verification(): void
    {
        $bill = $this->bill();

        Vendor::query()->firstOrFail()->delete();

        $html = $this->forms->html('tagihan-vendor', ['id' => $bill->id]);

        $this->assertStringContainsString('PEMASOK / VENDOR', $html);
        $this->assertStringContainsString('CV Sinar Baja Perkasa', $html);
        $this->assertStringContainsString('02.345.678.9-023.000', $html);
        $this->assertStringContainsString('208.000.000,00', $html);
    }

    /**
     * A bank account closed since the transfer went out still says where the
     * money moved.
     *
     * MELALUI BANK is the only line on the voucher that answers "how", and
     * fin_bank_accounts soft-deletes when an account is closed — which is
     * exactly what happens to the operational account of a finished year while
     * its vouchers stay in the file. Ruled beside a stated Rp 58.000.000,00,
     * the sheet records that money left and cannot say through what.
     */
    public function test_a_closed_bank_account_still_says_how_the_money_moved(): void
    {
        $payment = $this->vendorPayment();

        BankAccount::query()->firstOrFail()->delete();

        $html = $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('MELALUI BANK', 'Bank Central Asia — 5420123456 (a.n. PT Nusantara Karya Integrasi)'),
            $html,
        );
        $this->assertStringContainsString('58.000.000,00', $html);
    }

    /**
     * The SCREEN agrees with the PAPER about a deleted bill.
     *
     * allocationTargets() gained withTrashed so the printed voucher keeps
     * naming what it settled. PaymentAllocation::payable() — the one thing
     * the payment screen reads for the same row — still used a plain find(),
     * so the sheet in the operator's hand named BIL/2026/VIII/0012 while the
     * screen beside it showed a bare row number. Two answers to one question,
     * and the paper is the one that gets signed.
     */
    public function test_the_screen_names_a_deleted_bill_the_way_the_voucher_does(): void
    {
        $payment = $this->vendorPayment();
        $allocation = $payment->allocations()->firstOrFail();

        ApBill::query()->firstOrFail()->delete();

        $screen = (new PaymentAllocationResource($allocation->fresh()))->toArray(request());
        $paper = $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        $this->assertSame('BIL/2026/VIII/0012', $screen['payable_code']);
        $this->assertStringContainsString('BIL/2026/VIII/0012', $paper);
    }

    /**
     * The fourth kind of allocation, and the one nothing else in this file
     * reached: a replenishment settles a KAS KECIL DRAWER.
     *
     * fin_petty_cash_funds soft-deletes — a site closes and the drawer is
     * retired — while the transfer that topped it up stays for ever. findMany()
     * returns a deleted row NOT AS NULL BUT AS NOTHING, so without withTrashed
     * the row fell through `$targets[...] ?? []` and printed a real
     * Rp 1.200.000,00 with no drawer code and no drawer name beside it: cash
     * on a signed voucher that names nothing it went to.
     *
     * PENERIMA stays ruled either way, and that is not the same failure — a
     * drawer is not a person, so nobody "received" this money in the sense the
     * line asks about. What the sheet owes its reader is WHICH drawer.
     */
    public function test_a_deleted_petty_cash_drawer_keeps_its_name_on_the_replenishment(): void
    {
        $payment = $this->pettyCashReplenishment();

        PettyCashFund::query()->firstOrFail()->delete();

        $html = $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        $this->assertStringContainsString('Kas kecil (dana imprest)', $html);
        $this->assertStringContainsString('KK-GRAHA', $html);
        $this->assertStringContainsString('Kas Kecil Proyek Graha Sentosa', $html);
        $this->assertStringContainsString('1.200.000,00', $html);
        // A drawer is not a party: nothing here can name who took the cash.
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('PENERIMA'), $html);
    }

    /**
     * ONE SHEET, ONE COUNTERPARTY, IN ALL THREE PLACES IT IS PRINTED.
     *
     * PENERIMA, the party over the first signature rule and the ALOKASI table
     * are three renderings of one answer, and the first two are resolved
     * through a memo of the settled documents. The memo is what makes this
     * assertable rather than obvious: a cache that answered for the wrong
     * payment would put one vendor's name over another vendor's bill, on the
     * rule that vendor signs. Both vouchers are rendered in ONE process for
     * exactly that reason.
     */
    public function test_the_recipient_the_signature_and_the_allocation_name_one_counterparty(): void
    {
        $vendorSheet = $this->forms->html('bukti-pembayaran', ['id' => $this->vendorPayment()->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('PENERIMA', 'CV Sinar Baja Perkasa'),
            $vendorSheet,
        );
        $this->assertStringContainsString('<div class="party">CV Sinar Baja Perkasa</div>', $vendorSheet);
        // And the document that proves it, in the table the two lines above
        // are derived from.
        $this->assertStringContainsString('BIL/2026/VIII/0012', $vendorSheet);

        // A second voucher in the same process, settling a GL liability: it
        // must NOT inherit the vendor named on the sheet printed a moment ago.
        $payrollSheet = $this->forms->html('bukti-pembayaran', ['id' => $this->payrollPayment()->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('PENERIMA'), $payrollSheet);
        $this->assertStringNotContainsString('CV Sinar Baja Perkasa', $payrollSheet);
        $this->assertStringContainsString('2-1110', $payrollSheet);
    }

    /**
     * And the three renderings cost ONE lookup, not three.
     *
     * The invariant above is about what the sheet SAYS; this one is about how
     * it gets there, and no markup assertion can reach it — the HTML is
     * byte-identical either way. PENERIMA, the signature party and the ALOKASI
     * table each ask allocationTargets() what this payment settled, so a
     * one-allocation voucher ran fin_ap_bills three times and prc_vendors
     * three times to answer one question three ways. Counted the way
     * SettingsCacheTest counts, because a memo that quietly stops memoising is
     * invisible in every other kind of test.
     */
    public function test_one_voucher_looks_its_settled_documents_up_once(): void
    {
        $payment = $this->vendorPayment();

        $counts = [];

        DB::listen(function (QueryExecuted $query) use (&$counts): void {
            foreach (['fin_ap_bills', 'prc_vendors'] as $table) {
                // Identifier quotes differ per driver (SQLite ", MySQL `).
                if (preg_match('/[`"]'.$table.'[`"]/', $query->sql) === 1) {
                    $counts[$table] = ($counts[$table] ?? 0) + 1;
                }
            }
        });

        $this->forms->html('bukti-pembayaran', ['id' => $payment->id]);

        $this->assertSame(1, $counts['fin_ap_bills'] ?? 0, 'the settled bill is looked up once per sheet');
        $this->assertSame(1, $counts['prc_vendors'] ?? 0, 'and its vendor with it');
    }

    /** A masa whose journal was deleted still points at the voucher it used. */
    public function test_the_tax_register_keeps_a_deleted_journals_number(): void
    {
        $anchor = $this->taxObligation();
        $journal = $this->journal();
        $anchor->forceFill(['journal_id' => $journal->id])->save();

        $journal->delete();

        $html = $this->forms->html('kewajiban-pajak', ['id' => $anchor->id]);

        $this->assertStringContainsString('JV/2026/VIII/0004', $html);
    }
}
