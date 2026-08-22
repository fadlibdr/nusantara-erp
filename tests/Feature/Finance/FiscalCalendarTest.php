<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Models\Notification;
use Modules\Finance\Models\FiscalPeriod;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The forward calendar, and the two commands that keep it ahead of the company.
 *
 * The failure has a date on it. Every posting in the system goes through
 * JournalService::assertPeriodOpen(), which needs a fin_fiscal_periods row for
 * the document's month; the calendar stopped at December 2026, so on
 * 1 January 2027 the first invoice, the first bill and the first payment of the
 * year would all have refused at once — with a message about a table nobody
 * outside Finance knows exists.
 *
 * Creation is FORWARD-ONLY and never lazy. A period created on demand inside
 * assertPeriodOpen() would be created OPEN at the exact moment somebody
 * backdated into it, which is the one thing that guard exists to prevent.
 */
class FiscalCalendarTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;

    public function test_the_calendar_command_creates_the_months_ahead_that_are_missing(): void
    {
        Carbon::setTestNow('2026-08-15 05:30:00');
        $this->period(2026, 8);

        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();

        // August existed; September, October and November are new.
        $this->assertSame(4, FiscalPeriod::query()->count());

        foreach ([9, 10, 11] as $month) {
            $period = FiscalPeriod::query()->where('year', 2026)->where('month', $month)->first();
            $this->assertNotNull($period, "2026-{$month} should have been created.");
            $this->assertTrue($period->isOpen());
        }
    }

    public function test_the_calendar_command_never_reopens_a_period_that_was_closed(): void
    {
        Carbon::setTestNow('2026-08-15 05:30:00');
        $this->period(2026, 8);
        $this->period(2026, 9)->update(['status' => 'closed']);

        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();

        // firstOrCreate, never updateOrCreate: cron must not be able to undo a
        // close nobody asked it to undo.
        $this->assertTrue($this->period(2026, 9)->fresh()->isClosed());
    }

    public function test_the_calendar_command_is_idempotent_across_repeated_runs(): void
    {
        Carbon::setTestNow('2026-08-15 05:30:00');

        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();
        $first = FiscalPeriod::query()->orderBy('year')->orderBy('month')->get(['year', 'month', 'status'])->toArray();

        // Cron runs this every single day; the second and third run must be
        // no-ops rather than duplicates or status churn.
        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();
        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();

        $this->assertSame(4, FiscalPeriod::query()->count());
        $this->assertSame($first, FiscalPeriod::query()->orderBy('year')->orderBy('month')->get(['year', 'month', 'status'])->toArray());
    }

    public function test_the_calendar_command_creates_next_january_before_the_year_turns(): void
    {
        Carbon::setTestNow('2026-10-01 05:30:00');
        $this->seedLedger(2026); // 2026 complete, 2027 empty — the live shape

        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();

        // Three months ahead of 1 October is January, so 2027 exists a full
        // quarter before anybody dates a document into it.
        $this->assertNotNull(FiscalPeriod::query()->where('year', 2027)->where('month', 1)->first());
    }

    public function test_a_journal_dated_on_1_january_2027_posts_after_the_calendar_command_has_run(): void
    {
        Carbon::setTestNow('2026-12-20 05:30:00');
        $this->seedLedger(2026);

        $journal = $this->draftJournal([['1-1100', 2500000, 0], ['4-1100', 0, 2500000]], '2027-01-02');

        try {
            $this->journals()->post($journal);
            $this->fail('Expected a missing-period refusal before the calendar ran.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Belum ada periode fiskal untuk 2027-01-02', $e->getMessage());
        }

        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();

        $posted = $this->journals()->post($journal->fresh());
        $this->assertSame('2027-01-02', $posted->journal_date->toDateString());
    }

    public function test_the_calendar_command_notifies_finance_when_it_opens_a_new_year(): void
    {
        Carbon::setTestNow('2026-10-01 05:30:00');
        $this->seedLedger(2026);
        $this->adminUser(); // holds fin.post

        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();

        $notification = Notification::query()->where('event', Notification::SYSTEM)->first();

        $this->assertNotNull($notification, 'Finance should learn a new year exists in October, not on 1 January.');
        $this->assertSame('Kalender fiskal 2027 dibuat', $notification->title);
        $this->assertSame('periods', $notification->link);

        // A quarter of daily runs must not produce a quarter of copies.
        $this->artisan('fin:ensure-calendar', ['--months' => 3])->assertSuccessful();
        $this->assertSame(1, Notification::query()->where('event', Notification::SYSTEM)->count());
    }

    // -------------------------------------------------------- the explicit button

    public function test_generating_a_year_that_lies_entirely_before_a_closed_period_creates_it_closed(): void
    {
        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->period(2026, 1)->update(['status' => 'closed']);

        $result = $this->periods()->generateYear(2025);

        $this->assertSame(12, $result['created']);
        $this->assertSame('closed', $result['created_status']);
        $this->assertStringContainsString('semuanya DITUTUP', $result['message']);
        $this->assertStringContainsString('2026-01', $result['message']);

        // Twelve open months behind a closed one would break the ordering
        // invariant and open a backdating hole in the same click.
        $this->assertSame(12, FiscalPeriod::query()->where('year', 2025)->where('status', 'closed')->count());
    }

    public function test_generating_a_year_with_nothing_closed_after_it_creates_it_open(): void
    {
        Carbon::setTestNow('2026-08-15 09:00:00');

        $result = $this->periods()->generateYear(2027);

        $this->assertSame(12, $result['created']);
        $this->assertSame('open', $result['created_status']);
        $this->assertStringContainsString('12 periode terbuka', $result['message']);
    }

    /**
     * The closed-after rule alone was vacuous on a fresh installation: with
     * the 2026 calendar all open (the state fin:ensure-calendar leaves), the
     * review probe generated 2024 as twelve OPEN months and posted a journal
     * dated 2024-05-10 that assertPeriodOpen() had refused minutes earlier.
     * A year behind the calendar the company operates on is history whether
     * or not anyone has ever pressed Tutup Periode.
     */
    public function test_generating_a_past_year_when_nothing_was_ever_closed_still_creates_it_closed(): void
    {
        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->seedLedger(2026); // 2026-01..12 exist, all OPEN — nothing closed

        $result = $this->periods()->generateYear(2024);

        $this->assertSame(12, $result['created']);
        $this->assertSame('closed', $result['created_status']);
        $this->assertStringContainsString('semuanya DITUTUP', $result['message']);
        // The reason names the calendar it predates, not a closed period that
        // does not exist.
        $this->assertStringContainsString('sebelum periode paling awal 2026-01', $result['message']);

        // The backdating door the probe walked through is shut again.
        $journal = $this->draftJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]], '2024-05-10');

        try {
            $this->journals()->post($journal);
            $this->fail('Expected the generated 2024 periods to refuse a backdated journal.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2024-05 sudah ditutup', $e->getMessage());
        }
    }

    public function test_generating_a_year_leaves_existing_periods_untouched(): void
    {
        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->period(2026, 1)->update(['status' => 'closed']);
        $this->period(2026, 2);

        $result = $this->periods()->generateYear(2026);

        $this->assertSame(2, $result['existing']);
        $this->assertSame(10, $result['created']);
        $this->assertTrue($this->period(2026, 1)->fresh()->isClosed());
        $this->assertTrue($this->period(2026, 2)->fresh()->isOpen());
    }

    public function test_generating_a_year_more_than_two_years_ahead_is_refused(): void
    {
        Carbon::setTestNow('2026-08-15 09:00:00');

        try {
            // A typo'd 2072 would otherwise create twelve rows nobody notices.
            $this->periods()->generateYear(2072);
            $this->fail('Expected a range refusal.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('terlalu jauh ke depan', $e->getMessage());
        }

        $this->assertSame(0, FiscalPeriod::query()->where('year', 2072)->count());
    }

    // ------------------------------------------------------------- close watch

    public function test_the_close_watch_command_reminds_when_an_ended_period_is_still_open_past_the_threshold(): void
    {
        Carbon::setTestNow('2026-08-15 08:15:00');
        $this->seedLedger(2026);
        $this->adminUser();

        $this->artisan('fin:close-watch')->assertSuccessful();

        $notification = Notification::query()->where('event', Notification::SYSTEM)->first();

        $this->assertNotNull($notification);
        // The OLDEST unclosed ended period, and only that one: the checklist is
        // the heaviest read in the module and this runs daily.
        $this->assertSame('Periode 2026-01 belum ditutup', $notification->title);
        $this->assertStringContainsString('hari lalu dan belum ditutup', $notification->body);
    }

    public function test_the_close_watch_command_stays_quiet_when_every_ended_period_is_closed(): void
    {
        Carbon::setTestNow('2026-08-15 08:15:00');
        $this->seedLedger(2026);
        $this->adminUser();

        // Everything that has ended is closed; August onwards is still running.
        $this->closeEverythingBefore(2026, 8);

        $this->artisan('fin:close-watch')->assertSuccessful();

        $this->assertSame(0, Notification::query()->where('event', Notification::SYSTEM)->count());
    }

    public function test_a_period_that_ended_inside_the_grace_window_is_not_nagged_about_yet(): void
    {
        // Default threshold is 10 days; July ended 5 days ago on this clock.
        Carbon::setTestNow('2026-08-05 08:15:00');
        $this->adminUser();
        $this->period(2026, 7);

        $this->artisan('fin:close-watch')->assertSuccessful();

        $this->assertSame(0, Notification::query()->where('event', Notification::SYSTEM)->count());
    }

    public function test_a_missing_fiscal_period_names_the_year_and_the_remedy_in_indonesian(): void
    {
        Carbon::setTestNow('2026-12-31 09:00:00');
        $this->seedLedger(2026);

        $journal = $this->draftJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]], '2027-01-02');

        try {
            $this->journals()->post($journal);
            $this->fail('Expected a missing-period refusal.');
        } catch (LogicException $e) {
            // The old English message named neither the year nor the fix, on
            // the busiest morning of the accounting year.
            $this->assertStringContainsString('Belum ada periode fiskal untuk 2027-01-02', $e->getMessage());
            $this->assertStringContainsString('Buat kalender fiskal 2027', $e->getMessage());
            $this->assertStringContainsString('Keuangan › Periode Fiskal', $e->getMessage());
        }

        $this->assertSame(0, DB::table('fin_fiscal_periods')->where('year', 2027)->count());
    }
}
