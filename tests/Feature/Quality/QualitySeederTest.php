<?php

namespace Tests\Feature\Quality;

use Modules\Core\Models\Location;
use Modules\Projects\Models\Project;
use Modules\Quality\Database\Seeders\QualityDatabaseSeeder;
use Modules\Quality\Models\ConcreteSample;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Models\Ncr;
use Modules\Subcontract\Models\Subcontract;
use Tests\ErpTestCase;

/**
 * P1-QC seeder — skips gracefully without the canon, and converges on a second
 * run (updateOrCreate / existence guards, CONVENTIONS §8).
 */
class QualitySeederTest extends ErpTestCase
{
    public function test_it_skips_gracefully_without_the_project_canon(): void
    {
        $this->seed(QualityDatabaseSeeder::class);

        $this->assertSame(0, Inspection::query()->count());
        $this->assertSame(0, ConcreteSample::query()->count());
    }

    public function test_it_seeds_the_quality_story_idempotently(): void
    {
        $project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ]);
        // The Engineering-seeded zone the seeder hangs its story on.
        Location::query()->create([
            'project_id' => $project->id,
            'code' => 'GSP-T1-L01-ZA',
            'kind' => 'zone',
            'name' => 'Zona A',
            'sort_order' => 1,
        ]);
        Subcontract::query()->create([
            'code' => 'SPK/2026/III/9001',
            'vendor_id' => 9001,
            'title' => 'Pekerjaan struktur',
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'status' => 'draft',
        ]);

        $this->seed(QualityDatabaseSeeder::class);
        $this->seed(QualityDatabaseSeeder::class); // second run must not duplicate

        $this->assertSame(1, InspectionTemplate::query()->where('code', 'Q7')->count());
        $this->assertSame(3, InspectionTemplate::query()->where('code', 'Q7')->first()->items()->count());
        $this->assertSame(1, Inspection::query()->count());
        $this->assertSame(1, Ncr::query()->count());
        $this->assertSame(1, ConcreteSample::query()->count());
        $this->assertSame(2, ConcreteSample::query()->first()->tests()->count());

        // The demo NCR is fully resolved (closed) so it blocks no handover.
        $this->assertSame('closed', Ncr::query()->first()->status->value);
        // The demo breaks pass — computed by the service, not typed.
        $this->assertTrue(ConcreteSample::query()->first()->tests->every(fn ($t) => (bool) $t->pass));
    }
}
