<?php

namespace Modules\Engineering\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Location;
use Modules\Engineering\Enums\DrawingStatus;
use Modules\Engineering\Models\Drawing;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\MaterialSubmittal;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Inventory\Models\Item;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;

/**
 * Demo dataset (CONVENTIONS §8 canon): the drawing → material → IPP chain on
 * PRJ-2026-001, with the documents in the states the chain requires — the
 * seeded IPP is approved, so its lines MUST ride approved submittals or the
 * demo would contradict the module's own gate.
 *
 * Idempotent via updateOrCreate on codes; skips gracefully when the Projects
 * canon is not seeded. Statuses and decisions are written directly with the
 * seeding note the ProjectsDatabaseSeeder baseline uses: maker-checker needs
 * two people and a seeder is nobody — the runtime paths are covered by
 * tests/Feature/Engineering.
 */
class EngineeringDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()->where('code', 'PRJ-2026-001')->first();

        if ($project === null) {
            return; // Projects canon not seeded yet — nothing to hang drawings on
        }

        $locations = $this->seedLocations($project);
        $drawings = $this->seedDrawings($project);
        $sds = $this->seedDrawingSubmittal($drawings['struktur']);
        $sms = $this->seedMaterialSubmittal($project);
        $this->seedIpp($project, $locations, $sds, $sms);
    }

    /** @return array<string, Location> */
    private function seedLocations(Project $project): array
    {
        $tower = Location::query()->updateOrCreate(['code' => 'GSP-T1'], [
            'project_id' => $project->id,
            'kind' => 'tower',
            'name' => 'Gedung Utama',
            'sort_order' => 1,
        ]);

        $floor = Location::query()->updateOrCreate(['code' => 'GSP-T1-L01'], [
            'project_id' => $project->id,
            'parent_id' => $tower->id,
            'kind' => 'floor',
            'name' => 'Lantai 1',
            'sort_order' => 1,
        ]);

        $zone = Location::query()->updateOrCreate(['code' => 'GSP-T1-L01-ZA'], [
            'project_id' => $project->id,
            'parent_id' => $floor->id,
            'kind' => 'zone',
            'name' => 'Zona A',
            'sort_order' => 1,
        ]);

        return ['tower' => $tower, 'floor' => $floor, 'zone' => $zone];
    }

    /** @return array<string, Drawing> */
    private function seedDrawings(Project $project): array
    {
        $struktur = Drawing::query()->updateOrCreate(
            ['project_id' => $project->id, 'number' => 'GSP-ST-101'],
            [
                'title' => 'Denah Pondasi Bore Pile Zona A',
                'discipline' => 'struktur',
                'planned_submit_date' => '2026-03-02',
                'status' => DrawingStatus::Approved, // mirror of the seeded SDS below
            ],
        );

        $arsitektur = Drawing::query()->updateOrCreate(
            ['project_id' => $project->id, 'number' => 'GSP-AR-201'],
            [
                'title' => 'Denah Arsitektur Lantai 1',
                'discipline' => 'arsitektur',
                'planned_submit_date' => '2026-04-01',
                'status' => DrawingStatus::BelumDiajukan,
            ],
        );

        $elv = Drawing::query()->updateOrCreate(
            ['project_id' => $project->id, 'number' => 'GSP-EL-301'],
            [
                'title' => 'Riser Diagram CCTV & Akses Kontrol',
                'discipline' => 'elv',
                'planned_submit_date' => '2026-05-04',
                'status' => DrawingStatus::BelumDiajukan,
            ],
        );

        return ['struktur' => $struktur, 'arsitektur' => $arsitektur, 'elv' => $elv];
    }

    private function seedDrawingSubmittal(Drawing $drawing): DrawingSubmittal
    {
        return DrawingSubmittal::query()->updateOrCreate(
            ['drawing_id' => $drawing->id, 'revision' => 'R0'],
            [
                'submitted_at' => '2026-03-05',
                'reviewer_party' => 'mk',
                'decision' => 'approved',
                'decided_at' => '2026-03-12',
                'notes' => 'Sesuai gambar kontrak; lanjutkan pelaksanaan.',
                'created_by' => $this->engineerId(),
            ],
        );
    }

    private function seedMaterialSubmittal(Project $project): MaterialSubmittal
    {
        $item = Item::query()->where('code', 'ITM-0007')->first(); // Ready Mix K-300

        return MaterialSubmittal::query()->updateOrCreate(
            ['project_id' => $project->id, 'material_name' => 'Ready Mix K-300'],
            [
                'item_id' => $item?->id,
                'brand' => 'Adhimix',
                'spec_reference' => 'SNI 2847:2019 — mutu fc\' 25 MPa',
                'sample_attached' => true,
                'submitted_at' => '2026-03-06',
                'reviewer_party' => 'mk',
                // 'approved', bukan 'approved_as_noted': IPP demo di bawah
                // menaiki material_approval baris ini, dan gerbang IppService
                // menuntut material 'approved' penuh (aturan asimetris) — SMS
                // as-noted membuat demo memajang IPP yang tidak bisa lolos
                // gerbangnya sendiri. Nada 'sambil memperbaiki' cukup diwakili
                // catatan; keputusannya sendiri disetujui bersih.
                'decision' => 'approved',
                'decided_at' => '2026-03-13',
                'notes' => 'Disetujui. Lampirkan hasil uji slump setiap pengecoran.',
                'created_by' => $this->engineerId(),
            ],
        );
    }

    private function seedIpp(Project $project, array $locations, DrawingSubmittal $sds, MaterialSubmittal $sms): void
    {
        $existing = WorkPermitIpp::query()
            ->where('project_id', $project->id)
            ->where('description', 'like', 'Pengecoran pondasi bore pile%')
            ->first();

        if ($existing !== null) {
            return; // idempotent: the demo IPP (auto-numbered code) already exists
        }

        /** @var WorkPermitIpp $ipp */
        $ipp = WorkPermitIpp::query()->create([
            'project_id' => $project->id,
            'scope' => 'struktur',
            'location_id' => $locations['zone']->id,
            // The concrete work package (B.2 carries the ready-mix BOQ item) —
            // the value a bon pointing at this IPP inherits as its header
            // attribution. Graceful null when the Projects WBS is not seeded;
            // the lookup insists on a BOQ-carrying leaf because that is what
            // IppService::assertWbsTaskIsWorkPackage would demand at runtime.
            'wbs_task_id' => WbsTask::query()
                ->where('project_id', $project->id)
                ->where('wbs_code', 'B.2')
                ->whereNotNull('boq_item_id')
                ->value('id'),
            'description' => 'Pengecoran pondasi bore pile Zona A (gambar GSP-ST-101 R0)',
            'planned_start' => '2026-03-16',
            'duration_days' => 14,
            'status' => DocumentStatus::Draft,
        ]);

        $ipp->materials()->create([
            'item_id' => Item::query()->where('code', 'ITM-0007')->value('id'),
            'description' => 'Ready Mix K-300',
            'qty' => 86,
            'unit' => 'm3',
        ]);
        $ipp->materials()->create([
            'item_id' => Item::query()->where('code', 'ITM-0002')->value('id'),
            'description' => 'Besi Beton D16',
            'qty' => 240,
            'unit' => 'btg',
        ]);
        $ipp->equipment()->create(['description' => 'Concrete pump', 'qty' => 1]);
        $ipp->equipment()->create(['description' => 'Truck mixer', 'qty' => 6, 'notes' => 'Rotasi dari batching plant Cakung']);
        $ipp->drawings()->create(['drawing_submittal_id' => $sds->id]);
        $ipp->materialApprovals()->create(['material_submittal_id' => $sms->id]);

        // Written approved directly: maker-checker needs two people and a
        // seeder is nobody. The runtime submit → approve path (and the gate)
        // is covered by tests/Feature/Engineering/IppGateTest.
        $ipp->forceFill(['status' => DocumentStatus::Approved])->save();

        $approver = $this->engineerId();

        $ipp->approvals()->create([
            'action' => 'approved',
            'user_id' => $approver,
            'note' => 'Gambar dan material pada baris IPP telah disetujui MK.',
        ]);
    }

    /**
     * The demo drafter login (Made Wirawan, Drafter/Estimator) when Iam is
     * seeded; null otherwise — created_by is nullable for exactly this case,
     * a row seeded by nobody.
     */
    private function engineerId(): ?int
    {
        return User::query()
            ->whereIn('email', ['estimator@nusantara.test', 'project-manager@nusantara.test'])
            ->orderByRaw("case email when 'estimator@nusantara.test' then 0 else 1 end")
            ->value('id');
    }
}
