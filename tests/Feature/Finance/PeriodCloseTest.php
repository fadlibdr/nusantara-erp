<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use LogicException;
use Modules\Crm\Models\Contract;
use Modules\Finance\Enums\PeriodStatus;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\PeriodEvent;
use Modules\Finance\Services\RevenueRecognitionService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Closing a period, and what closing one actually costs afterwards.
 *
 * `assertPeriodOpen()` was near-vacuous before this package: every seeded period
 * was open and nothing in the application ever closed one, so the guard the
 * whole posting layer runs through had never refused anything in production.
 * The tests below are what make it a real control — a journal, an invoice
 * approval and a cancellation all meet it from the other side.
 */
class PeriodCloseTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->seedLedger(2026);
        $this->closeEverythingBefore(2026, 6);
    }

    // --------------------------------------------------------- the refusals

    public function test_closing_a_period_with_a_hard_block_is_refused_and_leaves_the_period_open(): void
    {
        $journal = $this->draftJournal([['1-1100', 5000000, 0], ['4-1100', 0, 5000000]], '2026-06-30');

        try {
            $this->periods()->close($this->period(2026, 6), $this->closerUser());
            $this->fail('Expected a LogicException for a dangling document.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum dapat ditutup', $e->getMessage());
            $this->assertStringContainsString('1 dokumen menggantung', $e->getMessage());
        }

        $this->assertSame(PeriodStatus::Open, $this->period(2026, 6)->fresh()->status);
        $this->assertNull($this->period(2026, 6)->fresh()->closed_at);
        $this->assertSame(0, PeriodEvent::query()->count());
        // And the document it complained about is untouched.
        $this->assertNotNull($journal->fresh());
    }

    public function test_closing_a_period_with_an_unacknowledged_warning_is_refused(): void
    {
        $this->makeBankAccount();

        try {
            $this->periods()->close($this->period(2026, 6), $this->closerUser());
            $this->fail('Expected a LogicException for an unacknowledged warning.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Peringatan berikut belum diakui', $e->getMessage());
            $this->assertStringContainsString('rekonsiliasi bank', $e->getMessage());
        }

        $this->assertSame(PeriodStatus::Open, $this->period(2026, 6)->fresh()->status);
    }

    public function test_acknowledging_a_warning_without_a_written_reason_is_refused(): void
    {
        $this->makeBankAccount();

        try {
            $this->periods()->close($this->period(2026, 6), $this->closerUser(), 'ok', ['bank_reconciled']);
            $this->fail('Expected a LogicException for a missing reason.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Alasan wajib diisi', $e->getMessage());
        }

        $this->assertSame(PeriodStatus::Open, $this->period(2026, 6)->fresh()->status);
    }

    public function test_closing_an_already_closed_period_is_refused(): void
    {
        $this->periods()->close($this->period(2026, 6), $this->closerUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Periode Juni 2026 sudah ditutup.');

        $this->periods()->close($this->period(2026, 6)->fresh(), $this->closerUser());
    }

    public function test_a_later_period_cannot_be_closed_while_an_earlier_one_is_open(): void
    {
        $this->setPeriodStatus(2026, 4, 'open');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('periode 2026-04 masih terbuka');

        $this->periods()->close($this->period(2026, 6), $this->closerUser());
    }

    /**
     * The screen is a preview, never the input. A close that trusted what the
     * browser posted would make this race representable instead of impossible.
     */
    public function test_the_close_recomputes_the_checklist_and_refuses_when_a_blocker_appeared_after_the_screen_was_drawn(): void
    {
        $drawn = $this->periods()->payload($this->period(2026, 6));
        $this->assertTrue($drawn['summary']['can_close']);
        $this->assertSame(0, $drawn['summary']['blockers']);

        // …and now, between the screen and the button, somebody saves a JV.
        $this->draftJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]], '2026-06-11');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('1 dokumen menggantung');

        $this->periods()->close($this->period(2026, 6), $this->closerUser());
    }

    // ------------------------------------------------------------ the record

    public function test_a_clean_period_closes_and_records_who_closed_it_and_when(): void
    {
        $closer = $this->closerUser();

        $period = $this->periods()->close($this->period(2026, 6), $closer);

        $this->assertSame(PeriodStatus::Closed, $period->status);
        $this->assertTrue($period->isClosed());
        $this->assertSame($closer->id, (int) $period->closed_by);
        $this->assertSame('2026-08-15 09:00:00', $period->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Sri Wahyuni', $period->closedBy->name);
    }

    public function test_closing_records_an_event_carrying_the_checklist_snapshot_and_the_overridden_warning_keys(): void
    {
        $this->makeBankAccount();

        $this->periods()->close(
            $this->period(2026, 6),
            $this->closerUser(),
            'Rekening koran Juni dari BCA belum datang; ditutup tanpa rekonsiliasi.',
            ['bank_reconciled'],
        );

        /** @var PeriodEvent $event */
        $event = PeriodEvent::query()->sole();

        $this->assertSame('closed', $event->action->value);
        $this->assertSame('Ditutup', $event->action->label());
        $this->assertSame($this->closerUser()->id, (int) $event->user_id);
        $this->assertStringContainsString('BCA belum datang', $event->note);
        $this->assertSame(['bank_reconciled'], $event->overrides);

        // The snapshot is evidence: all eleven items as the closer saw them.
        $this->assertCount(11, $event->checklist);
        $snapshot = collect($event->checklist)->keyBy('key');
        $this->assertSame('fail', $snapshot['bank_reconciled']['status']);
        $this->assertSame('ok', $snapshot['dangling_documents']['status']);
    }

    public function test_a_ticked_box_on_a_warning_that_is_not_failing_does_not_demand_a_reason(): void
    {
        // The screen may send every key it rendered. Only warnings that ACTUALLY
        // fail are overrides, so a clean period closes with no note.
        $period = $this->periods()->close(
            $this->period(2026, 6),
            $this->closerUser(),
            null,
            ['bank_reconciled', 'tax_export_ready'],
        );

        $this->assertTrue($period->isClosed());
        $this->assertSame([], PeriodEvent::query()->sole()->overrides);
    }

    // ----------------------------------------- what a closed period refuses

    public function test_a_journal_can_no_longer_be_posted_into_a_closed_period(): void
    {
        $this->periods()->close($this->period(2026, 6), $this->closerUser());

        $journal = $this->draftJournal([['1-1100', 5000000, 0], ['4-1100', 0, 5000000]], '2026-06-30');

        try {
            $this->journals()->post($journal);
            $this->fail('Expected a LogicException for a closed fiscal period.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-06 sudah ditutup', $e->getMessage());
        }

        $this->assertSame(0, Journal::query()->where('status', 'posted')->count());
    }

    public function test_an_ar_invoice_dated_in_a_closed_period_can_no_longer_be_approved(): void
    {
        $contract = $this->contractInScope();

        // Block 5 first: the run for June has to be posted before June closes.
        $run = app(RevenueRecognitionService::class)->calculate(2026, 6, $this->closerUser());
        app(RevenueRecognitionService::class)->post($run, $this->closerUser());

        $this->periods()->close($this->period(2026, 6), $this->closerUser());

        $invoice = $this->arInvoices()->create([
            'customer_id' => $contract->customer_id,
            'contract_id' => $contract->id,
            'description' => 'Termin 1',
            'dpp' => 100000000,
            'ppn_rate' => 11.0,
            'invoice_date' => '2026-06-15',
        ]);
        $invoice->submit($this->financeUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Periode fiskal 2026-06 sudah ditutup');

        $this->arInvoices()->approve($invoice, $this->financeApprover());
    }

    /**
     * The path JournalService::reversalDate() has always described and that
     * nothing could reach until now: the original period is closed, so the
     * reversal is dated TODAY instead of inside a month that has been reported.
     */
    public function test_cancelling_a_document_booked_in_a_period_that_has_since_closed_reverses_it_today(): void
    {
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-20',
            'description' => 'Tagihan besi beton',
            'dpp' => 40000000,
            'ppn_amount' => 4400000,
        ]));

        $original = Journal::query()->where('reference_type', 'ap_bill')->sole();
        $this->assertSame('2026-06-20', $original->journal_date->toDateString());

        $this->periods()->close($this->period(2026, 6), $this->closerUser());

        $this->apBills()->cancel($bill->fresh(), $this->closerUser(), 'Barang tidak pernah dikirim.');

        $reversal = Journal::query()->where('reference_type', 'ap_bill_cancellation')->sole();

        // Not 2026-06-20: June is shut, and a cancellation discovered today is
        // an event of today.
        $this->assertSame('2026-08-15', $reversal->journal_date->toDateString());
        $this->assertSame('2026-06-20', $original->fresh()->journal_date->toDateString());
    }

    // ------------------------------------- the ordering guarantee, in code

    public function test_the_psak_115_run_refuses_to_post_while_that_months_payroll_run_is_still_draft(): void
    {
        $this->contractInScope();
        $this->makePayrollRun(2026, 6, 'draft', 'PYR/2026/06/002');

        $run = app(RevenueRecognitionService::class)->calculate(2026, 6, $this->closerUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payroll PYR/2026/06/002 untuk periode 2026-06 belum diposting');

        app(RevenueRecognitionService::class)->post($run, $this->closerUser());
    }

    public function test_the_psak_115_run_refuses_to_post_while_that_months_depreciation_run_is_still_draft(): void
    {
        $this->contractInScope();
        $this->makeDepreciationRun(2026, 6, 'draft', 'DPR/2026/VI/001');

        $run = app(RevenueRecognitionService::class)->calculate(2026, 6, $this->closerUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('persentase penyelesaian akan understated');

        app(RevenueRecognitionService::class)->post($run, $this->closerUser());
    }

    public function test_the_psak_115_run_posts_when_no_payroll_or_depreciation_run_exists_for_the_period(): void
    {
        // Only runs that EXIST but are unposted block. A company with HR not
        // yet live would otherwise never recognise revenue at all.
        $this->contractInScope();

        $run = app(RevenueRecognitionService::class)->calculate(2026, 6, $this->closerUser());
        $posted = app(RevenueRecognitionService::class)->post($run, $this->closerUser());

        $this->assertTrue($posted->isPosted());
    }

    public function test_the_psak_115_run_posts_once_the_payroll_run_it_waited_for_is_approved(): void
    {
        $this->contractInScope();
        $this->makePayrollRun(2026, 6, 'approved', 'PYR/2026/06/002');

        $run = app(RevenueRecognitionService::class)->calculate(2026, 6, $this->closerUser());

        $this->assertTrue(app(RevenueRecognitionService::class)->post($run, $this->closerUser())->isPosted());
    }

    // ------------------------------------------------------------------ helper

    private function contractInScope(): Contract
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

        return $contract;
    }
}
