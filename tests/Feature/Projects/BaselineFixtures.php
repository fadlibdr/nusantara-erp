<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Models\ProjectCost;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProgressService;
use Spatie\Permission\Models\Role;

/**
 * PRJ-2026-001 rebuilt exactly as it stands in database/database.sqlite, so the
 * EVM expectations are the live site's own numbers rather than invented ones.
 *
 * Queried against a copy of the live file: contract CTR/2026/I/0001 at
 * Rp 48.500.000.000, RAP/2026/0001 at Rp 42.173.913.043,47 and still
 * 'submitted', 3 parent + 8 leaf WBS rows whose weights close on exactly
 * 100,0000 and whose progress rolls up to exactly 55,0000%, and two material
 * cost rows totalling Rp 228.240.000 — Rp 209.500.000 on 05-03-2026 and
 * Rp 18.740.000 on 05-07-2026.
 *
 * Deliberately dumb: it assembles rows and never computes an expectation. Every
 * expected number is spelled out, with its arithmetic, in the test asserting it.
 */
trait BaselineFixtures
{
    /** The eight leaves: code, weight, planned start, planned end, progress. */
    protected const LEAVES = [
        ['A.1', 0.8008, '2026-02-02', '2026-03-31', 100],
        ['A.2', 1.0296, '2026-02-02', '2026-03-15', 100],
        ['B.1', 2.6162, '2026-02-09', '2026-03-20', 100],
        ['B.2', 28.0313, '2026-03-02', '2026-10-31', 65],
        ['B.3', 36.9962, '2026-02-23', '2026-10-15', 60],
        ['B.4', 15.8559, '2026-03-02', '2026-11-15', 60],
        ['C.1', 11.8884, '2026-03-16', '2027-03-31', 4.0604],
        ['C.2', 2.7816, '2026-03-23', '2027-06-30', 5],
    ];

    protected const RAP_TOTAL = 42173913043.47;

    /** RAP/2026/0001's own category split, which is what makes CPI unreliable. */
    protected const RAP_CATEGORIES = [
        'material' => 23347547534.51,
        'labor' => 4976334666.79,
        'subcon' => 10821807652.16,
        'equipment' => 178031790.79,
        'overhead' => 2850191399.22,
    ];

    protected function grahaProject(): Project
    {
        $customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);

        $contract = Contract::query()->create([
            'code' => 'CTR/2026/I/0001',
            'customer_id' => $customer->id,
            'title' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'status' => DocumentStatus::Approved,
        ]);

        $project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'contract_id' => $contract->id,
            'customer_id' => $customer->id,
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-02-02',
            'end_date' => '2027-07-31',
            'contract_value' => 48_500_000_000,
            'retention_pct' => 5,
            // The live file's stale header: week 8 says 62%, this says 2%.
            // Nothing in EVM reads it, and one test proves that.
            'planned_progress_pct' => 2,
        ]);

        $this->seedWbs($project, self::LEAVES);

        return $project->refresh();
    }

    /**
     * 3 parents + the given leaves, with the parents and the project header
     * rolled up by the real service rather than by hand.
     *
     * @param  list<array{0: string, 1: float, 2: string, 3: string, 4: float}>  $leaves
     */
    protected function seedWbs(Project $project, array $leaves): void
    {
        $project->wbsTasks()->whereNotNull('parent_id')->delete();
        $project->wbsTasks()->delete();

        $parents = [];
        $order = 0;

        foreach ($leaves as [$code, $weight, $start, $end, $progress]) {
            $parentCode = explode('.', $code)[0];

            if (! isset($parents[$parentCode])) {
                $order++;
                $parents[$parentCode] = $project->wbsTasks()->create([
                    'wbs_code' => $parentCode,
                    'name' => "Paket pekerjaan {$parentCode}",
                    'weight_pct' => 0,
                    'planned_start' => $start,
                    'planned_end' => $end,
                    'sort_order' => $order,
                ]);
            }

            $project->wbsTasks()->create([
                'parent_id' => $parents[$parentCode]->id,
                'wbs_code' => $code,
                'name' => "Pekerjaan {$code}",
                'weight_pct' => $weight,
                'planned_start' => $start,
                'planned_end' => $end,
                'progress_pct' => $progress,
                'sort_order' => $order,
            ]);
        }

        foreach ($parents as $parentCode => $parent) {
            $parent->forceFill([
                'weight_pct' => round((float) $project->wbsTasks()
                    ->where('parent_id', $parent->id)->sum('weight_pct'), 4),
            ])->save();
        }

        app(ProgressService::class)->recalcWbsRollups($project->refresh());
    }

    /**
     * RAP/2026/0001, with its five category lines so cost_coverage has a budget
     * to measure the realisasi against.
     */
    protected function makeRap(Project $project, float $total = self::RAP_TOTAL, string $status = 'submitted', bool $withItems = true): int
    {
        $boqId = DB::table('est_boqs')->insertGetId([
            'code' => 'BOQ/2026/'.str()->random(4),
            'title' => 'BOQ '.$project->code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rapId = DB::table('est_cost_budgets')->insertGetId([
            'code' => 'RAP/2026/'.str()->random(4),
            'boq_id' => $boqId,
            'project_id' => $project->id,
            'target_margin_pct' => 13,
            'total_budget' => $total,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withItems) {
            // est_cost_budget_items.boq_item_id is NOT NULL with a real FK, so
            // the budget lines need a BOQ item to hang off even though nothing
            // in EVM reads it.
            $sectionId = DB::table('est_boq_sections')->insertGetId([
                'boq_id' => $boqId,
                'section_no' => 'A',
                'name' => 'Pekerjaan utama',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $boqItemId = DB::table('est_boq_items')->insertGetId([
                'boq_id' => $boqId,
                'section_id' => $sectionId,
                'wbs_code' => 'A.1',
                'description' => 'Pekerjaan utama',
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $total,
                'amount' => $total,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (self::RAP_CATEGORIES as $category => $amount) {
                DB::table('est_cost_budget_items')->insert([
                    'cost_budget_id' => $rapId,
                    'boq_item_id' => $boqItemId,
                    'cost_category' => $category,
                    'description' => 'Anggaran '.$category,
                    'qty' => 1,
                    'unit' => 'ls',
                    'unit_price' => $amount * $total / self::RAP_TOTAL,
                    'amount' => $amount * $total / self::RAP_TOTAL,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $rapId;
    }

    /**
     * Through the MODEL, not DB::table, and that matters: the `date` cast makes
     * SQLite store '2026-07-05 00:00:00', which is exactly what turns the POC
     * engine's `cost_date <= '2026-07-05'` into a string compare that returns
     * false. Inserting a bare '2026-07-05' would hide the defect the
     * reconciliation block exists to report.
     */
    protected function addCost(Project $project, string $date, float $amount, string $category = 'material'): void
    {
        ProjectCost::query()->create([
            'project_id' => $project->id,
            'cost_date' => $date,
            'cost_category' => $category,
            'reference_type' => 'test',
            'reference_id' => random_int(1, 1_000_000),
            'description' => 'Biaya uji',
            'amount' => $amount,
        ]);
    }

    /** The live file's two material rows, Rp 228.240.000 in total. */
    protected function addDemoCosts(Project $project): void
    {
        $this->addCost($project, '2026-03-05', 209_500_000);
        $this->addCost($project, '2026-07-05', 18_740_000);
    }

    /**
     * Maker-checker needs two people, so every test that approves a baseline
     * needs two of these.
     */
    protected function userWith(string $permission, string $name = 'Pengguna'): User
    {
        $role = Role::findOrCreate('role-'.str_replace('.', '-', $permission), 'web');
        $role->givePermissionTo($permission);

        /** @var User $user */
        $user = User::query()->create([
            'name' => $name,
            'email' => str()->random(10).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
