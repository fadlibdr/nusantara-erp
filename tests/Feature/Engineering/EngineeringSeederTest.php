<?php

namespace Tests\Feature\Engineering;

use Modules\Core\Models\Location;
use Modules\Engineering\Database\Seeders\EngineeringDatabaseSeeder;
use Modules\Engineering\Models\Drawing;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\MaterialSubmittal;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * The demo dataset, proven here instead of via `migrate:fresh --seed` — the
 * development sqlite is live data and is never written by a work session.
 *
 * Canon (CONVENTIONS §8): everything hangs off PRJ-2026-001; when that project
 * is not seeded the seeder must skip gracefully, and running it twice must not
 * duplicate anything (updateOrCreate idempotence).
 */
class EngineeringSeederTest extends ErpTestCase
{
    private function canonProject(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-02-02',
            'end_date' => '2027-07-31',
        ]);
    }

    public function test_it_skips_gracefully_when_the_canon_project_is_absent(): void
    {
        $this->seed(EngineeringDatabaseSeeder::class);

        $this->assertSame(0, Drawing::query()->count());
        $this->assertSame(0, WorkPermitIpp::query()->count());
    }

    public function test_it_seeds_three_drawings_two_submittals_one_ipp_and_is_idempotent(): void
    {
        $this->canonProject();

        $this->seed(EngineeringDatabaseSeeder::class);
        $this->seed(EngineeringDatabaseSeeder::class); // idempotent, not doubled

        $this->assertSame(3, Drawing::query()->count());
        $this->assertSame(1, DrawingSubmittal::query()->count());
        $this->assertSame(1, MaterialSubmittal::query()->count());
        $this->assertSame(1, WorkPermitIpp::query()->count());
        $this->assertGreaterThanOrEqual(2, Location::query()->count());

        // The chain the module exists for: the IPP's drawing line rides an
        // APPROVED submittal — a seeded gate violation would be a demo that
        // contradicts the module's own rule.
        $ipp = WorkPermitIpp::query()->firstOrFail();
        $this->assertSame('approved', $ipp->status->value);

        foreach ($ipp->drawings as $line) {
            $this->assertTrue($line->drawingSubmittal->decision?->permitsWork());
        }
    }
}
