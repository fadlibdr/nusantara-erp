<?php

namespace Tests\Unit\Projects;

use App\Models\User;
use Modules\Crm\Models\Customer;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Services\BoqService;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Modules\Projects\Services\DailyReportService;
use Modules\Projects\Services\ProgressService;
use Modules\Projects\Services\ProjectService;

/**
 * Hand-built Projects fixtures shared by the WBS / progress / BAST unit tests.
 *
 * Deliberately dumb: it only assembles rows. Every expected number, with its
 * arithmetic, lives in the test that asserts it.
 */
trait ProjectsFixtures
{
    protected function projects(): ProjectService
    {
        return app(ProjectService::class);
    }

    protected function progress(): ProgressService
    {
        return app(ProgressService::class);
    }

    protected function dailyReports(): DailyReportService
    {
        return app(DailyReportService::class);
    }

    protected function boqs(): BoqService
    {
        return app(BoqService::class);
    }

    protected function makeUser(string $email = 'pm@test.local'): User
    {
        return User::query()->create([
            'name' => 'Rina Wijaya',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    protected function makeCustomer(): Customer
    {
        return Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    protected function makeProject(array $data = []): Project
    {
        return Project::query()->create(array_merge([
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'city' => 'Jakarta Selatan',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'contract_value' => 10000000000,
            'retention_pct' => 5,
            'warranty_months' => 12,
            'status' => ProjectStatus::Preparation,
        ], $data));
    }

    /**
     * A BOQ whose section/item amounts are given verbatim by the caller, so a
     * test can pick numbers that do (or do not) divide evenly.
     *
     * @param  array<string, array<string, float>>  $sections  ['A' => ['A.1' => 100000, ...], ...]
     */
    protected function makeBoqWithAmounts(array $sections, array $overrides = []): Boq
    {
        $payload = [];

        foreach ($sections as $sectionNo => $items) {
            $lines = [];

            foreach ($items as $wbsCode => $amount) {
                $lines[] = [
                    'wbs_code' => $wbsCode,
                    'description' => "Pekerjaan {$wbsCode}",
                    'qty' => 1,
                    'unit' => 'ls',
                    'unit_price' => $amount,
                ];
            }

            $payload[] = [
                'section_no' => $sectionNo,
                'name' => "Seksi {$sectionNo}",
                'items' => $lines,
            ];
        }

        return $this->boqs()->create(array_merge([
            'title' => 'RAB uji bobot WBS',
            'sections' => $payload,
        ], $overrides));
    }

    /**
     * Attach a hand-made WBS tree to a project.
     *
     * @param  array<string, array<string, float>>  $tree  ['A' => ['A.1' => 40.0, ...], ...]
     *                                                     parent weight = sum of its children
     * @return array<string, WbsTask> keyed by wbs_code
     */
    protected function makeWbsTree(Project $project, array $tree): array
    {
        $tasks = [];
        $parentOrder = 0;

        foreach ($tree as $parentCode => $children) {
            $parentOrder++;

            $parent = $project->wbsTasks()->create([
                'parent_id' => null,
                'wbs_code' => $parentCode,
                'name' => "Paket {$parentCode}",
                'weight_pct' => round(array_sum($children), 4),
                'progress_pct' => 0,
                'sort_order' => $parentOrder,
            ]);

            $tasks[$parentCode] = $parent;
            $childOrder = 0;

            foreach ($children as $childCode => $weight) {
                $childOrder++;

                $tasks[$childCode] = $project->wbsTasks()->create([
                    'parent_id' => $parent->id,
                    'wbs_code' => $childCode,
                    'name' => "Pekerjaan {$childCode}",
                    'weight_pct' => $weight,
                    'progress_pct' => 0,
                    'sort_order' => $childOrder,
                ]);
            }
        }

        return $tasks;
    }

    protected function leafWeightTotal(Project $project): float
    {
        return round((float) $project->wbsTasks()->whereDoesntHave('children')->sum('weight_pct'), 4);
    }
}
