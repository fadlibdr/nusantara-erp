<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\CalendarEvents;
use Modules\Core\Support\WatchedDeadlines;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Models\Project;
use Modules\ServiceDesk\Models\PreventiveSchedule;
use Modules\ServiceDesk\Models\ServiceContract;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * GET core/calendar — the month behind the kalender screen.
 *
 * The endpoint windows the same scopes the deadline watcher tiers (composed,
 * never copied), plus the calendar-only plan sources, filtered to the module
 * .view permissions the CALLER holds — seeing a plan is reading, so "nothing
 * here" and "nothing you may see" read the same. The clock is frozen on the
 * demo's own day (1 Aug 2026) so the demo-data pin below stays honest.
 */
class CalendarApiTest extends ErpTestCase
{
    private const TODAY = '2026-08-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

    // -------------------------------------------------------------- fixtures

    private function actAsHolderOf(string ...$permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('peran-'.substr(md5(implode('|', $permissions)), 0, 8), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pemegang Izin',
            'email' => substr(md5(implode('|', $permissions)), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    private function quotation(array $overrides = []): Quotation
    {
        return Quotation::query()->create(array_merge([
            'customer_id' => $this->customer()->id,
            'title' => 'Penawaran Upgrade CCTV Gudang',
            'scope_type' => 'system_integration',
            'total' => 33_966_000,
            'status' => 'approved',
        ], $overrides));
    }

    private function project(array $overrides = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-00'.(Project::query()->count() + 1),
            'name' => 'Instalasi ELV & Data Center Bank Artha Nusantara',
            'type' => 'system_integration',
            'status' => 'active',
        ], $overrides));
    }

    private function serviceContract(array $overrides = []): ServiceContract
    {
        return ServiceContract::query()->create(array_merge([
            'customer_id' => $this->customer()->id,
            'name' => 'Kontrak Pemeliharaan ELV Bank Artha Nusantara',
            'period_start' => '2026-04-01',
            'period_end' => '2027-03-31',
            'contract_value' => 480_000_000,
            'status' => 'active',
        ], $overrides));
    }

    private function pmVisit(string $name, string $due, array $contractOverrides = []): PreventiveSchedule
    {
        return PreventiveSchedule::query()->create([
            'service_contract_id' => $this->serviceContract($contractOverrides)->id,
            'name' => $name,
            'frequency' => 'monthly',
            'next_due_date' => $due,
            'is_active' => true,
        ]);
    }

    private function events(string $month = '2026-08'): array
    {
        return $this->getJson('/api/core/calendar?month='.$month)->assertOk()->json('data');
    }

    // ------------------------------------------------------------- windowing

    public function test_calendar_returns_only_events_dated_inside_the_requested_month(): void
    {
        $this->quotation(['valid_until' => '2026-07-31']);
        $this->quotation(['valid_until' => '2026-08-01']);
        $this->quotation(['valid_until' => '2026-08-31']);
        $this->quotation(['valid_until' => '2026-09-01']);
        $this->actAsHolderOf('crm.view');

        $dates = array_column($this->events('2026-08'), 'date');

        // Half-open window: the 1st and the 31st are inside, both neighbours
        // (31 Jul, 1 Sep) are not.
        $this->assertSame(['2026-08-01', '2026-08-31'], $dates);
    }

    public function test_calendar_includes_an_event_stored_with_a_midnight_timestamp_on_the_last_day_of_the_month(): void
    {
        $quotation = $this->quotation(['valid_until' => '2026-08-31']);
        $this->actAsHolderOf('crm.view');

        // The storage footgun this endpoint must survive: the model's date
        // cast stores "2026-08-31 00:00:00", which sorts AFTER the bare string
        // "2026-08-31" — a naive `<= last-day` comparison drops the row.
        // On SQLite the text is stored verbatim (that IS the footgun); MySQL
        // folds it into a DATE and reads back the bare day.
        $stored = (string) DB::table('crm_quotations')->where('id', $quotation->id)->value('valid_until');
        $this->assertSame(DB::getDriverName() === 'sqlite' ? '2026-08-31 00:00:00' : '2026-08-31', $stored);

        $event = collect($this->events('2026-08'))->firstWhere('kind', 'quotation_valid_until');
        $this->assertNotNull($event);
        $this->assertSame('2026-08-31', $event['date']);
        $this->assertSame($quotation->code, $event['code']);
    }

    public function test_calendar_defaults_to_the_current_server_month_and_reports_it_as_as_of(): void
    {
        $this->quotation(['valid_until' => '2026-08-15']);
        $this->actAsHolderOf('crm.view');

        $response = $this->getJson('/api/core/calendar')->assertOk();

        $this->assertSame('2026-08', $response->json('meta.month'));
        $this->assertSame(self::TODAY, $response->json('meta.as_of'));
        $this->assertSame(1, $response->json('meta.count'));
    }

    public function test_calendar_rejects_a_malformed_month_parameter(): void
    {
        $this->actAsHolderOf('crm.view');

        foreach (['2026-13', '2026-0', 'Agustus-2026', '2026-08-01'] as $bad) {
            $this->getJson('/api/core/calendar?month='.$bad)
                ->assertStatus(422)
                ->assertJson(['message' => 'Parameter month harus berformat YYYY-MM.']);
        }
    }

    public function test_calendar_accepts_a_valid_month_parameter(): void
    {
        $this->quotation(['valid_until' => '2026-09-15']);
        $this->actAsHolderOf('crm.view');

        $response = $this->getJson('/api/core/calendar?month=2026-09')->assertOk();

        $this->assertSame('2026-09', $response->json('meta.month'));
        $this->assertSame(1, $response->json('meta.count'));
        // as_of stays the SERVER's today even when browsing another month —
        // it is the only 'today' the client may ring.
        $this->assertSame(self::TODAY, $response->json('meta.as_of'));
    }

    // ------------------------------------------------------------ permission

    public function test_calendar_hides_events_of_a_module_the_caller_cannot_view(): void
    {
        $this->quotation(['valid_until' => '2026-08-15']);
        Milestone::query()->create([
            'project_id' => $this->project()->id,
            'name' => 'Progres fisik 80% — syarat penagihan Termin 3',
            'due_date' => '2026-08-20',
        ]);
        $this->actAsHolderOf('prj.view');

        $events = $this->events();

        $this->assertSame(['milestone_due'], array_column($events, 'kind'));
        $this->assertSame(['Proyek' => 1], $this->getJson('/api/core/calendar?month=2026-08')->json('meta.departments'));
    }

    public function test_calendar_shows_the_same_events_to_a_viewer_of_that_module(): void
    {
        $this->quotation(['valid_until' => '2026-08-15']);
        Milestone::query()->create([
            'project_id' => $this->project()->id,
            'name' => 'Progres fisik 80% — syarat penagihan Termin 3',
            'due_date' => '2026-08-20',
        ]);
        $this->actAsHolderOf('crm.view');

        $kinds = array_column($this->events(), 'kind');

        $this->assertContains('quotation_valid_until', $kinds);
        $this->assertNotContains('milestone_due', $kinds);
    }

    public function test_calendar_shows_a_termin_billing_plan_to_a_finance_viewer_not_a_sales_viewer(): void
    {
        $contract = Contract::query()->create([
            'customer_id' => $this->customer()->id,
            'title' => 'Instalasi ELV & Data Center Bank Artha Nusantara',
            'scope_type' => 'system_integration',
            'value' => 9_800_000_000,
            'status' => 'approved',
        ]);
        ContractTermin::query()->create([
            'contract_id' => $contract->id,
            'termin_no' => 2,
            'name' => 'Triwulan II 25%',
            'percent' => 25,
            'amount' => 120_000_000,
            'due_date' => '2026-08-10',
        ]);

        // Rencana tagih is finance's event: it files under Keuangan and links
        // to the 'siap-tagih' screen, so fin.view sees it...
        $this->actAsHolderOf('fin.view');
        $termin = collect($this->events())->firstWhere('kind', 'termin_due');
        $this->assertNotNull($termin);
        $this->assertSame('Keuangan', $termin['department']);
        $this->assertSame('siap-tagih', $termin['link']);

        // ...and a sales-only viewer does not (the contract itself would be
        // theirs, but its start/end carry no August dates here).
        $this->actAsHolderOf('crm.view');
        $this->assertNotContains('termin_due', array_column($this->events(), 'kind'));
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/core/calendar')->assertUnauthorized();
    }

    // ------------------------------------------------- sources and their scopes

    public function test_calendar_excludes_a_won_quotation_from_the_validity_source(): void
    {
        // The registry's own scope, reused not copied: won drops out there,
        // so it must drop out here.
        $this->quotation(['valid_until' => '2026-08-31', 'won_at' => '2026-07-01 10:00:00']);
        $this->actAsHolderOf('crm.view');

        $this->assertSame([], $this->events());
    }

    /**
     * Status only filters the FUTURE. A closed project's target date in a past
     * month is history — hiding it makes December read as if the plan that
     * structured that month never existed, while project_start stays visible.
     */
    public function test_a_closed_projects_past_target_date_stays_in_history_months(): void
    {
        $this->project(['end_date' => '2026-07-18', 'status' => 'closed']);
        $this->actAsHolderOf('prj.view');

        $kinds = array_column($this->events('2026-07'), 'kind');
        $this->assertContains('project_end', $kinds);
    }

    public function test_a_closed_projects_future_target_date_is_not_shown_as_a_plan(): void
    {
        $this->project(['end_date' => '2026-08-20', 'status' => 'closed']);
        $this->actAsHolderOf('prj.view');

        $this->assertNotContains('project_end', array_column($this->events(), 'kind'));
    }

    public function test_calendar_derives_the_fiscal_period_end_as_a_finance_event_on_the_last_day(): void
    {
        $this->openFiscalYear(2026);
        $this->actAsHolderOf('fin.view');

        $events = $this->events();

        $this->assertCount(1, $events);
        $this->assertSame([
            'date' => '2026-08-31',
            'department' => 'Keuangan',
            'title' => 'Tutup buku Agustus 2026',
            'code' => '2026-08',
            'link' => 'periods',
            'kind' => 'fiscal_period_end',
            'value' => null,
        ], $events[0]);
    }

    public function test_calendar_shows_a_payroll_payment_date_to_an_hr_viewer(): void
    {
        PayrollRun::query()->create([
            'period_year' => 2026,
            'period_month' => 8,
            'run_type' => 'regular',
            'payment_date' => '2026-08-25',
            'total_net' => 166_638_981,
            'status' => 'approved',
        ]);
        $this->actAsHolderOf('hr.view');

        $event = collect($this->events())->firstWhere('kind', 'payroll_payment');

        $this->assertNotNull($event);
        $this->assertSame('2026-08-25', $event['date']);
        $this->assertSame('SDM', $event['department']);
        $this->assertSame('Pembayaran gaji', $event['title']);
        // json_encode drops the '.0' of a whole float, so compare as float.
        $this->assertSame(166_638_981.0, (float) $event['value']);
    }

    public function test_calendar_shows_a_preventive_visit_whose_service_contract_is_active(): void
    {
        $this->pmVisit('PM CCTV Bulanan', '2026-08-05');
        $this->actAsHolderOf('svc.view');

        $event = collect($this->events())->firstWhere('kind', 'pm_visit');

        $this->assertNotNull($event);
        $this->assertSame('2026-08-05', $event['date']);
        $this->assertSame('Layanan', $event['department']);
        // A named plan is its own best title.
        $this->assertSame('PM CCTV Bulanan', $event['title']);
        $this->assertSame('r/servicedesk/preventive-schedules', $event['link']);
    }

    public function test_calendar_hides_a_preventive_visit_of_an_expired_service_contract(): void
    {
        // Mirrors PreventiveService's own guard: a visit planned under an
        // expired contract is not a visit anyone will make.
        $this->pmVisit('PM CCTV Bulanan', '2026-08-05', ['status' => 'expired']);
        $this->actAsHolderOf('svc.view');

        $this->assertSame([], $this->events());
    }

    // ----------------------------------------------------------- degradation

    public function test_calendar_skips_a_source_whose_table_is_missing_instead_of_crashing(): void
    {
        $this->quotation(['valid_until' => '2026-08-15']);
        $this->actAsHolderOf('crm.view');
        Schema::drop('crm_guarantees');
        // The per-process schema memo was already primed by setUp()'s flush +
        // earlier queries in THIS test; the drop invalidates it mid-test.
        WatchedDeadlines::flushSchemaMemo();

        $response = $this->getJson('/api/core/calendar?month=2026-08')->assertOk();

        // The dropped table is one skipped line; every other source still ran.
        $this->assertSame(1, $response->json('meta.skipped'));
        $this->assertSame(count(CalendarEvents::sources()) - 1, $response->json('meta.checked'));
        $this->assertContains('quotation_valid_until', array_column($response->json('data'), 'kind'));
    }

    // ------------------------------------------------------------------- cap

    public function test_calendar_caps_a_month_at_five_hundred_events_and_reports_the_real_total(): void
    {
        // Unreachable on the demo data (August 2026 holds 4 events), so the
        // volume is fabricated: five sources at their per-source limit of 120
        // rows each = 600 events, spread over 1–28 August.
        $customer = $this->customer();
        $project = $this->project();
        $contract = $this->serviceContract();
        $day = static fn (int $i): string => sprintf('2026-08-%02d', ($i % 28) + 1);

        foreach ([0, 1] as $chunk) {
            $range = range($chunk * 60, $chunk * 60 + 59);
            DB::table('crm_quotations')->insert(array_map(static fn (int $i): array => [
                'code' => sprintf('QTN/2026/VIII/%04d', $i + 1),
                'customer_id' => $customer->id,
                'title' => 'Penawaran '.$i,
                'scope_type' => 'system_integration',
                'valid_until' => $day($i),
                'status' => 'approved',
            ], $range));
            DB::table('hr_payroll_runs')->insert(array_map(static fn (int $i): array => [
                'code' => sprintf('PYR/2026/08/%03d', $i + 1),
                // (period_year, period_month, run_type) is unique — the years
                // are nonsense on purpose, only payment_date matters here.
                'period_year' => 1900 + $i,
                'period_month' => 8,
                'payment_date' => $day($i),
            ], $range));
            DB::table('svc_preventive_schedules')->insert(array_map(static fn (int $i): array => [
                'service_contract_id' => $contract->id,
                'name' => 'PM Berkala '.$i,
                'next_due_date' => $day($i),
            ], $range));
            DB::table('prj_milestones')->insert(array_map(static fn (int $i): array => [
                'project_id' => $project->id,
                'name' => 'Milestone '.$i,
                'due_date' => $day($i),
            ], $range));
            DB::table('prj_bast')->insert(array_map(static fn (int $i): array => [
                'code' => sprintf('BAST/2026/VIII/%04d', $i + 1),
                'project_id' => $project->id,
                'bast_type' => 'bast1',
                'handover_date' => $day($i),
            ], $range));
        }

        $this->actingAs($this->adminUser(), 'sanctum');
        $response = $this->getJson('/api/core/calendar?month=2026-08')->assertOk();

        $this->assertSame(500, $response->json('meta.count'));
        $this->assertCount(500, $response->json('data'));
        $this->assertSame(600, $response->json('meta.total'));
        $this->assertTrue($response->json('meta.capped'));
        // Legend counts are tallied BEFORE the cap — they stay whole truths.
        $this->assertSame(600, array_sum($response->json('meta.departments')));
        $this->assertSame(240, $response->json('meta.departments.Proyek'));
        // Earliest-first: the truncation cuts the tail of the month, never the head.
        $this->assertSame('2026-08-01', $response->json('data.0.date'));
    }

    // ------------------------------------------------------ the demo month, pinned

    public function test_calendar_reproduces_the_four_august_events_the_demo_dataset_holds(): void
    {
        // The live demo's August 2026, rebuilt row for row in memory: two PM
        // visits (SVC/2026/III/0001), one quotation validity end (Rp 33,97 jt)
        // and the 2026-08 period close. Everything else contributes zero.
        $contract = $this->serviceContract();
        foreach ([['PM CCTV Bulanan', '2026-08-05'], ['PM Akses Kontrol & Alarm Bulanan', '2026-08-12']] as [$name, $due]) {
            PreventiveSchedule::query()->create([
                'service_contract_id' => $contract->id,
                'name' => $name,
                'frequency' => 'monthly',
                'next_due_date' => $due,
                'is_active' => true,
            ]);
        }
        $quotation = $this->quotation(['valid_until' => '2026-08-31']);
        $this->openFiscalYear(2026);
        $this->actingAs($this->adminUser(), 'sanctum');

        $response = $this->getJson('/api/core/calendar?month=2026-08')->assertOk();

        $this->assertSame(4, $response->json('meta.count'));
        $this->assertSame(['Layanan' => 2, 'Keuangan' => 1, 'Penjualan' => 1], $response->json('meta.departments'));

        // Sorted by (date, department, code): the two visits, then the two
        // month-end events with Keuangan before Penjualan.
        $this->assertSame(
            [
                ['2026-08-05', 'pm_visit', 'PM CCTV Bulanan'],
                ['2026-08-12', 'pm_visit', 'PM Akses Kontrol & Alarm Bulanan'],
                ['2026-08-31', 'fiscal_period_end', 'Tutup buku Agustus 2026'],
                ['2026-08-31', 'quotation_valid_until', 'Penawaran berlaku s/d'],
            ],
            array_map(static fn (array $event): array => [$event['date'], $event['kind'], $event['title']], $response->json('data')),
        );

        $qtn = $response->json('data.3');
        $this->assertSame($quotation->code, $qtn['code']);
        $this->assertSame(33_966_000.0, (float) $qtn['value']);
        $this->assertSame('r/crm/quotations', $qtn['link']);

        // The endpoint contract, pinned once: exactly these keys, this order.
        $this->assertSame(['date', 'department', 'title', 'code', 'link', 'kind', 'value'], array_keys($qtn));
        $this->assertSame(
            ['month', 'as_of', 'departments', 'count', 'total', 'capped', 'checked', 'skipped', 'truncated_sources'],
            array_keys($response->json('meta')),
        );
        $this->assertSame(0, $response->json('meta.truncated_sources'));
        $this->assertFalse($response->json('meta.capped'));
        $this->assertSame(count(CalendarEvents::sources()), $response->json('meta.checked'));
        $this->assertSame(0, $response->json('meta.skipped'));
    }
}
