<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Services\PeriodCloseService;
use Modules\Finance\Services\RevenueRecognitionService;
use Modules\Finance\Services\TaxExportService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * What "tutup buku" asserts, item by item, in both directions.
 *
 * Each item earns its severity here. A hard block must be something whose
 * omission cannot be repaired after the close; a warning must be something the
 * business may legitimately not have, or may only get later from a third party.
 * A hard block a company cannot satisfy is as bad as no control at all, so both
 * halves are asserted: the bad thing is refused AND the legitimate case still
 * passes.
 *
 * Every test pins Carbon to 15 August 2026 and works on June 2026, so "the
 * period has ended" is settled and never the accidental cause of a failure.
 */
class PeriodCloseChecklistTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;

    private const YEAR = 2026;

    private const MONTH = 6;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->seedLedger(2026);
        // Ordering is enforced, so every earlier month is closed first —
        // otherwise every test here would fail on earlier_periods_closed.
        $this->closeEverythingBefore(self::YEAR, self::MONTH);
    }

    // ------------------------------------------------------- block 1: ended

    public function test_a_month_that_has_not_ended_yet_cannot_be_closed(): void
    {
        // 2026-08 is the month Carbon is pinned inside: it ends on the 31st.
        $item = $this->assertItem(2026, 8, 'period_ended', PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        $this->assertStringContainsString('2026-08-31', $item['detail']);
        $this->assertStringContainsString('belum lewat', $item['detail']);

        $this->assertItem(self::YEAR, self::MONTH, 'period_ended', PeriodCloseService::BLOCK, PeriodCloseService::OK);
    }

    // ------------------------------------------------------ block 2: ordering

    public function test_an_open_earlier_period_blocks_closing_a_later_one_and_names_the_oldest(): void
    {
        $this->setPeriodStatus(2026, 3, 'open');
        $this->setPeriodStatus(2026, 5, 'open');

        $item = $this->assertItem(self::YEAR, self::MONTH, 'earlier_periods_closed',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        // The OLDEST one, not all of them: that is the one to close next.
        $this->assertStringContainsString('Maret 2026', $item['detail']);
        $this->assertStringNotContainsString('Mei 2026', $item['detail']);
        $this->assertSame('periods', $item['link']);
    }

    public function test_a_calendar_that_simply_has_no_earlier_months_does_not_block(): void
    {
        // An installation that went live in June 2026: months 1-5 were never
        // created. The rule is "every EXISTING earlier period", not "every
        // earlier month", or such a company could never close anything.
        FiscalPeriod::query()
            ->whereRaw('(year * 100 + month) < ?', [self::YEAR * 100 + self::MONTH])
            ->delete();

        $this->assertItem(self::YEAR, self::MONTH, 'earlier_periods_closed',
            PeriodCloseService::BLOCK, PeriodCloseService::OK);
    }

    // --------------------------------------------- block 3: dangling documents

    public function test_a_draft_journal_dated_inside_the_period_is_reported_as_a_dangling_document(): void
    {
        $journal = $this->draftJournal([['1-1100', 5000000, 0], ['4-1100', 0, 5000000]], '2026-06-30');

        $item = $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString($journal->code, $item['detail']);
        $this->assertStringContainsString('tidak akan pernah bisa diposting', $item['detail']);
        $this->assertSame('r/finance/journals', $item['link']);
    }

    public function test_the_dangling_item_flips_to_complete_once_the_document_is_actually_posted(): void
    {
        // The checklist is COMPUTED, never stored: the same question asked
        // twice, either side of one posting, must give two different answers.
        $journal = $this->draftJournal([['1-1100', 5000000, 0], ['4-1100', 0, 5000000]], '2026-06-30');

        $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        $this->journals()->post($journal, $this->closerUser()->id);

        $item = $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::OK);
        $this->assertSame(0, $item['count']);
    }

    public function test_a_submitted_ap_bill_dated_inside_the_period_is_reported_as_a_dangling_document(): void
    {
        $bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-20',
            'description' => 'Tagihan besi beton',
            'dpp' => 40000000,
            'ppn_amount' => 4400000,
        ]);
        $bill->submit($this->financeUser());

        $item = $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString($bill->code, $item['detail']);
        $this->assertStringContainsString('diajukan', $item['detail']);
    }

    public function test_a_draft_payroll_run_for_the_period_is_reported_as_a_dangling_document(): void
    {
        $this->makePayrollRun(self::YEAR, self::MONTH, 'draft', 'PYR/2026/06/002');

        $item = $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        $this->assertStringContainsString('PYR/2026/06/002', $item['detail']);
    }

    public function test_a_draft_depreciation_run_for_the_period_is_reported_as_a_dangling_document(): void
    {
        $this->makeDepreciationRun(self::YEAR, self::MONTH, 'draft', 'DPR/2026/VI/001');

        $item = $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        $this->assertStringContainsString('DPR/2026/VI/001', $item['detail']);
    }

    public function test_a_rejected_invoice_is_not_a_dangling_document_because_it_can_still_be_re_dated(): void
    {
        $invoice = $this->invoiceIn('2026-06-15');
        $invoice->submit($this->financeUser());
        $invoice->reject($this->financeApprover(), 'Nilai termin salah.');

        $this->assertSame(DocumentStatus::Rejected, $invoice->fresh()->status);
        $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::OK);
    }

    public function test_inventory_documents_are_not_dangling_when_perpetual_inventory_is_off(): void
    {
        $warehouseId = DB::table('inv_warehouses')->insertGetId([
            'code' => 'WH-01', 'name' => 'Gudang Pusat', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inv_issues')->insert([
            'code' => 'ISS/2026/VI/0001',
            'warehouse_id' => $warehouseId,
            'issue_date' => '2026-06-12',
            'purpose' => 'Pemakaian material struktur lantai 3',
            'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Perpetual: the issue posts to the ledger, so its date is pinned.
        $this->setSetting('accounting.perpetual_inventory', true);
        $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        // Periodic: a stock movement writes no ledger row at all, so closing
        // the month costs it nothing and blocking on it would be theatre.
        $this->setSetting('accounting.perpetual_inventory', false);
        $this->assertItem(self::YEAR, self::MONTH, 'dangling_documents',
            PeriodCloseService::BLOCK, PeriodCloseService::OK);
    }

    // ------------------------------------------------------- warnings 6 and 7

    public function test_a_missing_payroll_run_is_a_warning_and_not_a_block(): void
    {
        $this->makeEmployee();

        $item = $this->assertItem(self::YEAR, self::MONTH, 'payroll_present',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);
        $this->assertStringContainsString('Juni 2026', $item['detail']);

        // A posted run for the month answers it.
        $this->makePayrollRun(self::YEAR, self::MONTH, 'approved', 'PYR/2026/06/001');
        $this->assertItem(self::YEAR, self::MONTH, 'payroll_present',
            PeriodCloseService::WARN, PeriodCloseService::OK);
    }

    public function test_a_company_with_no_employees_is_not_even_warned_about_payroll(): void
    {
        $this->assertItem(self::YEAR, self::MONTH, 'payroll_present',
            PeriodCloseService::WARN, PeriodCloseService::NA);
    }

    public function test_a_missing_depreciation_run_is_a_warning_and_not_a_block(): void
    {
        $this->makeDepreciableAsset();

        $item = $this->assertItem(self::YEAR, self::MONTH, 'depreciation_present',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);
        // The consequence is named out loud: a skipped month can never be
        // depreciated later, because runForPeriod() only moves forward.
        $this->assertStringContainsString('hilang permanen', $item['detail']);

        $this->makeDepreciationRun(self::YEAR, self::MONTH, 'posted', 'DPR/2026/VI/001');
        $this->assertItem(self::YEAR, self::MONTH, 'depreciation_present',
            PeriodCloseService::WARN, PeriodCloseService::OK);
    }

    // ------------------------------------------------------------- warning 8

    public function test_an_unreconciled_bank_account_is_a_warning_and_not_a_block(): void
    {
        $this->makeBankAccount();

        $item = $this->assertItem(self::YEAR, self::MONTH, 'bank_reconciled',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);

        $this->assertStringContainsString('BCA Operasional', $item['detail']);
        // The reason it is not a block, said in the message the closer reads.
        $this->assertStringContainsString('setelah tutup bulan', $item['detail']);
        // A blocked account is named WITH its reason, not with "(0 item
        // terbuka)" — a sentence whose own words claim nothing is outstanding
        // while demanding a permanent override note.
        $this->assertStringContainsString('Belum ada rekening koran', $item['detail']);
        $this->assertStringNotContainsString('0 item terbuka', $item['detail']);
    }

    public function test_an_account_with_a_statement_still_reports_its_open_items_by_count(): void
    {
        $bank = $this->makeBankAccount();
        $this->importBareStatement((int) $bank->id, '2026-06-01', '2026-06-30', 5000000, 1);

        $item = $this->assertItem(self::YEAR, self::MONTH, 'bank_reconciled',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);

        // Not blocked — the statement is there — so the count is the message.
        $this->assertStringContainsString('BCA Operasional (1 item terbuka)', $item['detail']);
    }

    // ------------------------------------------------------------ warning 10

    /**
     * The disagreement a GL-only settlement leaves behind. The manual-JV probe
     * settled a Rp 111.000.000 bill with Dr 2-1100 / Cr 1-1210: trial balance
     * still balanced, bank bridge still closes — the sub-ledger tie-out is the
     * only checklist line that can see it.
     */
    public function test_a_gl_only_settlement_breaks_the_subledger_tie_out_and_names_the_difference(): void
    {
        $bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-10',
            'description' => 'Tagihan semen curah',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]);
        $this->approveBill($bill);

        // Properly booked, the sides agree: Cr 2-1100 111jt = outstanding 111jt.
        $this->assertItem(self::YEAR, self::MONTH, 'subledger_tied',
            PeriodCloseService::WARN, PeriodCloseService::OK);

        // The probe: settle it in the ledger only. The bill still says
        // outstanding Rp 111.000.000 — that disagreement is the finding.
        $this->postJournal(
            [['2-1100', 111000000, 0], ['1-1210', 0, 111000000]],
            '2026-06-20',
            'Pelunasan hutang lewat JV saja',
        );

        $item = $this->assertItem(self::YEAR, self::MONTH, 'subledger_tied',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);

        $this->assertStringContainsString('2-1100', $item['detail']);
        $this->assertStringContainsString('111.000.000,00', $item['detail']);
        $this->assertStringContainsString('JV manual', $item['detail']);
        // Only the broken side is counted; AR still ties.
        $this->assertSame(1, $item['count']);
    }

    public function test_a_bill_paid_through_the_payment_stage_keeps_the_subledger_tied(): void
    {
        $bank = $this->makeBankAccount();
        $bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-10',
            'description' => 'Tagihan besi beton',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]);
        $this->approveBill($bill);

        // The legitimate path — submit, approve, post — moves the bill and the
        // control account together, so the tie-out stays quiet.
        $this->approvedOutgoingPayment([
            'payment_date' => '2026-06-20',
            'bank_account_id' => $bank->id,
            'amount' => 111000000,
        ], [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 111000000]]);

        $this->assertItem(self::YEAR, self::MONTH, 'subledger_tied',
            PeriodCloseService::WARN, PeriodCloseService::OK);
    }

    /**
     * amount_paid is a lifetime figure; the tie-out must not read it. A July
     * payment settles a June bill: June's period-end GL still carries the
     * payable, and so must the sub-ledger side AS AT 30 June.
     */
    public function test_a_payment_dated_after_period_end_does_not_untie_the_period_being_closed(): void
    {
        $bank = $this->makeBankAccount();
        $bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-10',
            'description' => 'Tagihan baja ringan',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]);
        $this->approveBill($bill);

        $this->approvedOutgoingPayment([
            'payment_date' => '2026-07-05',
            'bank_account_id' => $bank->id,
            'amount' => 111000000,
        ], [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 111000000]]);

        // amount_paid now says lunas, but as at 30 June both sides still carry
        // Rp 111.000.000 — and agree.
        $this->assertSame(0.0, $bill->fresh()->outstanding());
        $this->assertItem(self::YEAR, self::MONTH, 'subledger_tied',
            PeriodCloseService::WARN, PeriodCloseService::OK);
    }

    // ------------------------------------------------------------- warning 9

    public function test_an_invoice_without_a_faktur_number_makes_the_tax_export_item_a_warning(): void
    {
        $this->approveInvoice($this->invoiceIn('2026-06-10'));

        $item = $this->assertItem(self::YEAR, self::MONTH, 'tax_export_ready',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);

        $this->assertStringContainsString('Nomor faktur pajak belum diisi', $item['detail']);
        $this->assertStringContainsString('datang dari DJP', $item['detail']);
    }

    /**
     * Looking at the checklist may not change anything.
     *
     * itemTaxExportReady() runs the real e-Bupot export, which used to MINT the
     * nomor bukti potong for any withholding that had none — so previewing a
     * close bound a permanent legal reference number to a bill and spent a
     * counter from the BP-YYYYMM sequence, for a closer who may hold nothing
     * beyond fin.view. The bill is now reported as a blocker and left alone;
     * issuing the numbers is its own POST on fin.approve.
     */
    public function test_previewing_the_checklist_does_not_issue_a_bukti_potong_number(): void
    {
        $vendor = $this->makeVendor(['npwp' => '01.334.556.7-007.000', 'is_subcontractor' => true]);
        $tax = $this->makePphFinalTax();
        $tax->forceFill(['object_code' => '28-403-01'])->save();

        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'description' => 'Opname subkon struktur Juni',
            'dpp' => 100_000_000,
            'pph_tax_id' => $tax->id,
            'pph_amount' => 2_650_000,
            'bill_date' => '2026-06-20',
            'vendor_invoice_no' => 'INV-SUB-6',
        ]));

        // The population that predates migration 2026_08_02_001112.
        $bill->forceFill(['bupot_no' => null])->save();

        $item = $this->assertItem(self::YEAR, self::MONTH, 'tax_export_ready',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);

        $this->assertStringContainsString('Nomor bukti potong', $item['detail']);
        $this->assertNull($bill->fresh()->bupot_no);

        // And once the numbers are issued deliberately, the item passes.
        app(TaxExportService::class)->issueBuktiPotongNumbers(self::YEAR, self::MONTH);

        $this->assertNotNull($bill->fresh()->bupot_no);
        $this->assertItem(self::YEAR, self::MONTH, 'tax_export_ready',
            PeriodCloseService::WARN, PeriodCloseService::OK);
    }

    // --------------------------------------------------------------- block 5

    public function test_an_unposted_psak_115_run_is_a_hard_block(): void
    {
        $this->contractInScope();

        $item = $this->assertItem(self::YEAR, self::MONTH, 'revenue_recognition_posted',
            PeriodCloseService::BLOCK, PeriodCloseService::FAIL);

        $this->assertStringContainsString('Belum ada run PSAK 115', $item['detail']);

        $run = app(RevenueRecognitionService::class)->calculate(self::YEAR, self::MONTH, $this->closerUser());
        app(RevenueRecognitionService::class)->post($run, $this->closerUser());

        $this->assertItem(self::YEAR, self::MONTH, 'revenue_recognition_posted',
            PeriodCloseService::BLOCK, PeriodCloseService::OK);
    }

    public function test_the_psak_115_block_degrades_to_a_warning_when_a_later_run_is_already_posted(): void
    {
        $this->contractInScope();

        // July is measured and posted; June can now never be posted, because
        // post() refuses an earlier period after a later one. A hard block
        // nobody can satisfy is as bad as no control, so it degrades.
        $july = app(RevenueRecognitionService::class)->calculate(2026, 7, $this->closerUser());
        app(RevenueRecognitionService::class)->post($july, $this->closerUser());

        $item = $this->assertItem(self::YEAR, self::MONTH, 'revenue_recognition_posted',
            PeriodCloseService::WARN, PeriodCloseService::FAIL);

        $this->assertStringContainsString($july->fresh()->code, $item['detail']);
        $this->assertStringContainsString('2026-07', $item['detail']);
    }

    // ------------------------------------------------------------- the empty period

    public function test_a_period_with_nothing_in_it_at_all_reports_no_blockers_and_no_warnings(): void
    {
        $items = $this->periods()->checklist(self::YEAR, self::MONTH);
        $summary = $this->periods()->summary($this->period(self::YEAR, self::MONTH), $items);

        $this->assertSame(0, $summary['blockers'], $this->explain($items));
        $this->assertSame(0, $summary['warnings'], $this->explain($items));
        $this->assertTrue($summary['can_close']);
        $this->assertNull($summary['close_blocked_reason']);
        $this->assertCount(11, $items);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A statement header with $openLines unmatched lines, written straight to
     * the tables — the checklist only needs "a statement exists and something
     * is open", not a full MT940 import.
     */
    private function importBareStatement(int $bankAccountId, string $start, string $end, float $closing, int $openLines): void
    {
        $statementId = DB::table('fin_bank_statements')->insertGetId([
            'code' => 'BST/2026/06/000'.(DB::table('fin_bank_statements')->count() + 1),
            'bank_account_id' => $bankAccountId,
            'source_format' => 'mt940',
            'currency' => 'IDR',
            'period_start' => $start,
            'period_end' => $end,
            'opening_balance' => 0,
            'closing_balance' => $closing,
            'line_count' => $openLines,
            'content_hash' => hash('sha256', $bankAccountId.$start.$end.$closing),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($lineNo = 1; $lineNo <= $openLines; $lineNo++) {
            DB::table('fin_bank_statement_lines')->insert([
                'bank_statement_id' => $statementId,
                'line_no' => $lineNo,
                'entry_date' => $end,
                'direction' => 'credit',
                'amount' => 5000000,
                'description' => 'Setoran tunai tanpa dokumen',
                'match_status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function invoiceIn(string $date): ArInvoice
    {
        $contract = $this->makeContract($this->makeCustomer());

        return $this->arInvoices()->create([
            'customer_id' => $contract->customer_id,
            'contract_id' => $contract->id,
            'description' => 'Termin 1',
            'dpp' => 100000000,
            'ppn_rate' => 11.0,
            'invoice_date' => $date,
        ]);
    }

    private function contractInScope(): void
    {
        $contract = $this->makeContract($this->makeCustomer(), ['value' => 1000000000]);

        Project::query()->create([
            'code' => 'PRJ-2026-901',
            'name' => 'Proyek '.$contract->code,
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    private function explain(array $items): string
    {
        return implode("\n", array_map(
            fn (array $item): string => "{$item['key']} [{$item['severity']}/{$item['status']}] {$item['detail']}",
            $items,
        ));
    }
}
