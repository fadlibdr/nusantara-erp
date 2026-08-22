<?php

namespace Tests\Unit\Core;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\CalendarEvents;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Tests\ErpTestCase;

/**
 * CalendarEvents::window — the aggregation beneath GET core/calendar.
 *
 * What this file pins that the API tests cannot see directly: the half-open
 * window boundaries, the department/permission derivation for EVERY source
 * (so a new watcher entry with an unmapped table prefix fails here, not at
 * request time), the two-single-day-events rule for spans, and the per-source
 * fetch limit.
 */
class CalendarEventsTest extends ErpTestCase
{
    private const CALENDAR_ONLY_KINDS = [
        'fiscal_period_end', 'payroll_payment', 'project_start', 'project_end',
        'contract_start', 'bast_handover', 'pm_visit',
    ];

    private function august(): array
    {
        return CalendarEvents::window(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    public function test_the_window_is_half_open_so_both_month_edges_land_inside_and_the_neighbours_do_not(): void
    {
        $customer = $this->customer();

        // Model date casts store midnight timestamps ("2026-08-31 00:00:00"),
        // the storage form that breaks naive BETWEEN comparisons.
        foreach (['2026-07-31', '2026-08-01', '2026-08-31', '2026-09-01'] as $validUntil) {
            Quotation::query()->create([
                'customer_id' => $customer->id,
                'title' => 'Penawaran berlaku s/d '.$validUntil,
                'scope_type' => 'system_integration',
                'total' => 33_966_000,
                'status' => 'approved',
                'valid_until' => $validUntil,
            ]);
        }

        $window = $this->august();

        $this->assertSame(
            ['2026-08-01', '2026-08-31'],
            array_column($window['events'], 'date'),
        );
        $this->assertSame([], $window['skipped']);
        $this->assertSame(count(CalendarEvents::sources()), $window['checked']);
    }

    public function test_the_department_map_covers_every_source_prefix_with_a_view_permission(): void
    {
        $sources = CalendarEvents::sources();

        // Every registry entry plus the seven calendar-only sources — a
        // watcher added to WatchedDeadlines joins the calendar automatically.
        $this->assertCount(count(WatchedDeadlines::entries()) + count(self::CALENDAR_ONLY_KINDS), $sources);

        $chips = ['Penjualan', 'Proyek', 'Keuangan', 'SDM', 'Pengadaan', 'Layanan', 'Aset', 'Persediaan'];
        $permission = '/^('.implode('|', PermissionSeeder::PREFIXES).')\.view$/';

        foreach ($sources as $source) {
            $this->assertContains($source['department'], $chips, "source [{$source['kind']}] maps to no known department chip");
            $this->assertMatchesRegularExpression($permission, $source['view_permission'], "source [{$source['kind']}] carries no seeded .view permission");
            $this->assertNotSame('', $source['link'], "source [{$source['kind']}] has no SPA link");
        }

        $byKind = array_column($sources, null, 'kind');

        // The one prefix fold: scm has no chip of its own — an SPK end is a
        // site event the PM plans around — but keeps its own view permission.
        $this->assertSame('Proyek', $byKind['subcontract_end']['department']);
        $this->assertSame('scm.view', $byKind['subcontract_end']['view_permission']);

        // The two finance-workflow overrides: rencana tagih and retention
        // refund file under Keuangan and answer to fin.view, because their
        // screens (siap-tagih / retensi) are finance's.
        $this->assertSame('Keuangan', $byKind['termin_due']['department']);
        $this->assertSame('fin.view', $byKind['termin_due']['view_permission']);
        $this->assertSame('Keuangan', $byKind['bast_retention_release']['department']);
        $this->assertSame('fin.view', $byKind['bast_retention_release']['view_permission']);
    }

    public function test_a_contract_span_yields_two_single_day_events(): void
    {
        Contract::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Instalasi ELV & Data Center Bank Artha Nusantara',
            'scope_type' => 'system_integration',
            'value' => 9_800_000_000,
            'status' => 'approved',
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-20',
        ]);

        $events = $this->august()['events'];

        // Never a span bar: mulai and berakhir are independent dots, and both
        // reuse the registry's contract_end scope (approved, not deleted).
        $this->assertSame(
            [['2026-08-03', 'contract_start'], ['2026-08-20', 'contract_end']],
            array_map(static fn (array $event): array => [$event['date'], $event['kind']], $events),
        );
        $this->assertSame(9_800_000_000.0, $events[0]['value']);
        $this->assertSame(9_800_000_000.0, $events[1]['value']);
    }

    public function test_the_per_source_fetch_limit_holds(): void
    {
        // 130 dated payroll runs in one month; the source may carry only 120
        // into the merge, earliest first.
        DB::table('hr_payroll_runs')->insert(array_map(static fn (int $i): array => [
            'code' => sprintf('PYR/2026/08/%03d', $i + 1),
            // (period_year, period_month, run_type) is unique — the years are
            // nonsense on purpose, only payment_date matters here.
            'period_year' => 1900 + $i,
            'period_month' => 8,
            'payment_date' => sprintf('2026-08-%02d', ($i % 28) + 1),
        ], range(0, 129)));

        $events = $this->august()['events'];

        $this->assertCount(CalendarEvents::FETCH_LIMIT, $events);
        $this->assertSame('2026-08-01', $events[0]['date']);
    }
}
