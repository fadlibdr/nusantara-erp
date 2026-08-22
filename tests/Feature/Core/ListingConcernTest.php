<?php

namespace Tests\Feature\Core;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Models\Customer;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * ApiController::listing() — the one server mechanism behind column sorting,
 * the Dari/Sampai date window, and the meta block list.js discovers both from.
 *
 * Before it, every header in every generic list was dead text, "semua laporan
 * November" was unanswerable on 49 of 51 resources, and the only ORDER BY a
 * user could get was whatever the controller hard-coded. The mechanism is
 * exercised here through already-adopted Projects endpoints because the
 * concern has no endpoint of its own — by design, it never will.
 */
class ListingConcernTest extends ErpTestCase
{
    /** @return array{0: Project, 1: Project, 2: Project} */
    private function threeProjects(): array
    {
        $make = fn (string $code, string $name, string $city = 'Jakarta') => Project::query()->create([
            'code' => $code, 'name' => $name, 'city' => $city,
            'type' => 'construction', 'status' => 'active',
        ]);

        return [
            $make('PRJ-2026-001', 'Alpha Tower'),
            $make('PRJ-2026-002', 'Beta Plaza'),
            $make('PRJ-2026-003', 'Citra Land'),
        ];
    }

    private function threeDailyReports(Project $project): void
    {
        foreach ([['LH-001', '2026-11-01'], ['LH-002', '2026-11-15'], ['LH-003', '2026-12-01']] as [$code, $date]) {
            DailyReport::query()->create([
                'code' => $code, 'project_id' => $project->id, 'report_date' => $date,
                'activities' => 'Pekerjaan struktur',
            ]);
        }
    }

    // ---------------------------------------------------------------- sorting

    public function test_sorting_by_a_whitelisted_column_holds_across_a_page_boundary(): void
    {
        $this->threeProjects();
        $admin = $this->adminUser();

        // The boundary is the whole point: client-side sorting of one page
        // looks identical on page 1 and falls apart at page 2.
        $pageOne = $this->actingAs($admin)->getJson('/api/projects?sort=name&dir=asc&per_page=2')->assertOk();
        $this->assertSame(['Alpha Tower', 'Beta Plaza'], array_column($pageOne->json('data'), 'name'));

        $pageTwo = $this->actingAs($admin)->getJson('/api/projects?sort=name&dir=asc&per_page=2&page=2')->assertOk();
        $this->assertSame(['Citra Land'], array_column($pageTwo->json('data'), 'name'));

        $descending = $this->actingAs($admin)->getJson('/api/projects?sort=name&dir=desc&per_page=2')->assertOk();
        $this->assertSame(['Citra Land', 'Beta Plaza'], array_column($descending->json('data'), 'name'));
    }

    public function test_equal_sort_keys_paginate_deterministically_via_the_id_tiebreak(): void
    {
        foreach (['PRJ-2026-001', 'PRJ-2026-002', 'PRJ-2026-003'] as $code) {
            Project::query()->create([
                'code' => $code, 'name' => 'Proyek Kembar', 'type' => 'construction', 'status' => 'active',
            ]);
        }
        $admin = $this->adminUser();

        $pageOne = $this->actingAs($admin)->getJson('/api/projects?sort=name&dir=asc&per_page=2')->json('data');
        $pageTwo = $this->actingAs($admin)->getJson('/api/projects?sort=name&dir=asc&per_page=2&page=2')->json('data');

        // Without the tiebreak, equal keys let a row appear twice (or never)
        // across the boundary; with it the three ids arrive exactly once.
        $ids = array_merge(array_column($pageOne, 'id'), array_column($pageTwo, 'id'));
        $this->assertCount(3, array_unique($ids));
    }

    public function test_an_unknown_sort_column_is_refused_naming_the_allowed_ones(): void
    {
        $this->threeProjects();

        // 'city' IS a real prj_projects column — refusal proves the whitelist
        // decides, not column existence. A silent fallback would show default
        // order under a header the user believes is sorted.
        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/projects?sort=city')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');

        $this->assertStringContainsString('contract_value', $response->json('message'));
    }

    public function test_an_injection_shaped_sort_is_refused_and_never_reaches_sql(): void
    {
        $this->threeProjects();
        $admin = $this->adminUser();

        foreach (['name; drop table prj_projects', 'customer.name', 'name)--'] as $evil) {
            $this->actingAs($admin)
                ->getJson('/api/projects?'.http_build_query(['sort' => $evil]))
                ->assertStatus(422);
        }

        // The table survived and the list still answers.
        $this->actingAs($admin)->getJson('/api/projects')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_a_list_that_declares_nothing_sortable_refuses_every_sort(): void
    {
        // The WBS picker list adopted listing() with an empty whitelist: its
        // tree order is semantic and must never be replaced by a user sort.
        $this->actingAs($this->adminUser())
            ->getJson('/api/projects/wbs-tasks?sort=name')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_a_direction_that_is_not_desc_normalizes_to_asc(): void
    {
        $this->threeProjects();

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/projects?sort=name&dir=sideways')
            ->assertOk();

        $this->assertSame('asc', $response->json('meta.dir'));
        $this->assertSame('Alpha Tower', $response->json('data.0.name'));
    }

    // ------------------------------------------------------------ date window

    public function test_the_date_window_is_inclusive_on_both_bounds(): void
    {
        [$project] = $this->threeProjects();
        $this->threeDailyReports($project);

        // Rows ON the boundary dates are included — the whereDate convention
        // every existing local date filter already follows.
        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/projects/daily-reports?date_from=2026-11-01&date_to=2026-11-15')
            ->assertOk();

        $this->assertSame(['LH-002', 'LH-001'], array_column($response->json('data'), 'code'));
    }

    public function test_a_malformed_date_bound_is_ignored_instead_of_crashing(): void
    {
        [$project] = $this->threeProjects();
        $this->threeDailyReports($project);
        $admin = $this->adminUser();

        // $request->date() throws on garbage; the concern must answer 200
        // unfiltered — a crafted link may not take the list down.
        foreach (['31-12-2026', 'garbage', '2026-13-45'] as $bad) {
            $this->actingAs($admin)
                ->getJson('/api/projects/daily-reports?'.http_build_query(['date_from' => $bad]))
                ->assertOk()
                ->assertJsonCount(3, 'data');
        }
    }

    public function test_date_params_on_a_list_without_a_date_column_are_ignored(): void
    {
        $this->threeProjects();

        $this->actingAs($this->adminUser())
            ->getJson('/api/projects?date_from=2026-01-01&date_to=2026-01-31')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    // ------------------------------------------------------------ the contract

    public function test_meta_advertises_the_whitelist_and_date_column_alongside_pagination(): void
    {
        [$project] = $this->threeProjects();
        $this->threeDailyReports($project);

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/projects/daily-reports')
            ->assertOk();

        // The whitelist exists in exactly one place (the controller); list.js
        // reads it from here instead of a client-side duplicate in schema.js.
        $this->assertSame(['code', 'report_date', 'manpower_count'], $response->json('meta.sortable'));
        $this->assertSame('report_date', $response->json('meta.date_column'));
        $this->assertNull($response->json('meta.sort'));
        $this->assertNull($response->json('meta.dir'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.current_page'));
    }

    public function test_meta_echoes_the_sort_actually_applied(): void
    {
        [$project] = $this->threeProjects();
        $this->threeDailyReports($project);

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/projects/daily-reports?sort=report_date&dir=desc')
            ->assertOk();

        $this->assertSame('report_date', $response->json('meta.sort'));
        $this->assertSame('desc', $response->json('meta.dir'));
        $this->assertSame('LH-003', $response->json('data.0.code'));
    }

    public function test_per_page_stays_uncapped_because_the_pickers_page_at_500(): void
    {
        $this->threeProjects();

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/projects?per_page=500')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertSame(500, $response->json('meta.per_page'));
    }

    public function test_every_documented_per_page_choice_is_accepted_and_echoed(): void
    {
        $this->threeProjects();
        $admin = $this->adminUser();

        // The list.js rows-per-page selector offers exactly these; the SPA
        // trusts meta.per_page as the applied value, so the echo is contract.
        foreach ([25, 50, 100, 200] as $perPage) {
            $response = $this->actingAs($admin)
                ->getJson('/api/projects?per_page='.$perPage)
                ->assertOk()
                ->assertJsonCount(3, 'data');

            $this->assertSame($perPage, $response->json('meta.per_page'));
        }
    }

    public function test_a_non_positive_per_page_falls_back_to_the_default_instead_of_crashing(): void
    {
        $this->threeProjects();
        $admin = $this->adminUser();

        // integer() casts garbage to 0; a non-positive page size has no sane
        // meaning, so the clamp falls back to the default rather than letting
        // a crafted ?per_page=abc link decide the query's shape.
        foreach (['abc', '0', '-5'] as $bad) {
            $response = $this->actingAs($admin)
                ->getJson('/api/projects?'.http_build_query(['per_page' => $bad]))
                ->assertOk()
                ->assertJsonCount(3, 'data');

            $this->assertSame(20, $response->json('meta.per_page'));
        }
    }

    public function test_a_resource_less_list_serialises_its_rows_flat_for_the_generic_reader(): void
    {
        // Change orders have no Resource class. Handing ok() the paginator
        // used to nest the rows ({ data: { data: [...] } }) one level deeper
        // than list.js reads, so that screen showed "Belum ada" over data.
        $customer = Customer::query()->create(['code' => 'CUST-0001', 'name' => 'PT Contoh', 'status' => 'active']);
        $contract = Contract::query()->create([
            'code' => 'CTR-0001', 'customer_id' => $customer->id, 'title' => 'Gedung Kantor',
            'scope_type' => 'construction', 'value' => 1000000, 'status' => 'approved',
        ]);
        ContractChangeOrder::query()->create([
            'code' => 'CCO-0001', 'contract_id' => $contract->id, 'change_date' => '2026-11-05',
            'title' => 'Tambah kanopi', 'value_change' => 250000, 'status' => 'draft',
        ]);

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/crm/contract-change-orders')
            ->assertOk();

        $this->assertSame('CCO-0001', $response->json('data.0.code'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * A typo'd column in a whitelist is an SQL error that surfaces only when
     * somebody sorts by it — not at deploy. Every adopted endpoint is asked
     * for its own advertised whitelist and then sorted by each entry, so the
     * typo fails here instead of under a clerk's click. Self-maintaining: the
     * list to check comes from meta, never from a duplicate in this file.
     */
    public function test_every_adopted_endpoint_answers_a_sort_on_each_column_it_advertises(): void
    {
        // ~250 requests in one test; the api throttle would 429 halfway and
        // fail the run for a reason that has nothing to do with sorting.
        $this->withoutMiddleware(ThrottleRequests::class);

        $admin = $this->adminUser();

        $endpoints = [
            '/api/crm/customers', '/api/crm/leads', '/api/crm/quotations', '/api/crm/contracts',
            '/api/crm/contract-change-orders', '/api/crm/guarantees',
            '/api/projects', '/api/projects/daily-reports', '/api/projects/weekly-progress',
            '/api/projects/milestones', '/api/projects/bast', '/api/projects/baselines',
            '/api/projects/defects', '/api/projects/safety-incidents', '/api/projects/manpower-assignments',
            '/api/projects/wbs-tasks',
            '/api/inventory/items', '/api/inventory/item-categories', '/api/inventory/warehouses',
            '/api/inventory/goods-receipts', '/api/inventory/issues', '/api/inventory/transfers',
            '/api/inventory/stock-adjustments',
            '/api/procurement/vendors', '/api/procurement/purchase-requisitions',
            '/api/procurement/purchase-orders', '/api/procurement/vendor-evaluations',
            '/api/subcontract/subcontracts', '/api/subcontract/progress-claims',
            '/api/estimation/boqs', '/api/estimation/ahsp', '/api/estimation/cost-budgets',
            '/api/hr/employees', '/api/hr/certificates', '/api/hr/payroll-runs', '/api/hr/attendance-recaps',
            '/api/iam/users', '/api/iam/roles',
            '/api/servicedesk/tickets', '/api/servicedesk/contracts',
            '/api/servicedesk/preventive-schedules', '/api/servicedesk/field-reports',
            '/api/assets/assets', '/api/assets/categories', '/api/assets/deployments',
            '/api/assets/maintenances', '/api/assets/depreciation-runs',
            '/api/core/audit-log',
        ];

        foreach ($endpoints as $endpoint) {
            $meta = $this->actingAs($admin)->getJson($endpoint)->assertOk()->json('meta');

            $this->assertIsArray($meta['sortable'] ?? null, "{$endpoint} does not advertise meta.sortable");
            $this->assertArrayHasKey('date_column', $meta, "{$endpoint} does not advertise meta.date_column");

            foreach ($meta['sortable'] as $column) {
                $this->actingAs($admin)
                    ->getJson($endpoint.'?'.http_build_query(['sort' => $column, 'dir' => 'desc']))
                    ->assertOk();
            }
        }
    }
}
