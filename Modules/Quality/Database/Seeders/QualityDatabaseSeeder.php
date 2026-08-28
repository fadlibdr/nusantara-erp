<?php

namespace Modules\Quality\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Location;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Enums\ItemResult;
use Modules\Quality\Enums\NcrStatus;
use Modules\Quality\Enums\WitnessParty;
use Modules\Quality\Models\ConcreteSample;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Models\Ncr;
use Modules\Quality\Services\ConcreteStrengthService;
use Modules\Subcontract\Models\Subcontract;

/**
 * Demo dataset (CONVENTIONS §8 canon): a concrete-pour quality story on
 * PRJ-2026-001 at the Engineering-seeded Zona A — a checklist (Q7), an approved
 * inspection off it, a resolved NCR, and a benda-uji set whose breaks pass.
 *
 * Idempotent via updateOrCreate / existence guards on codes and markers; skips
 * gracefully when the Projects canon or the Engineering locations are not
 * seeded. Statuses and the pass verdicts are written directly (a seeder is
 * nobody — maker-checker needs two people; pass is still the SERVICE'S
 * arithmetic, never a typed value). The runtime paths are covered by
 * tests/Feature/Quality.
 */
class QualityDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()->where('code', 'PRJ-2026-001')->first();

        if ($project === null) {
            return; // Projects canon not seeded yet
        }

        // Reuse the Engineering-seeded site breakdown; skip if it is not there.
        $zone = Location::query()->where('code', 'GSP-T1-L01-ZA')->where('project_id', $project->id)->first();

        if ($zone === null) {
            return;
        }

        $template = $this->seedTemplate();
        $this->seedInspection($project, $zone, $template);
        $this->seedNcr($project, $zone);
        $this->seedConcrete($project, $zone);
    }

    private function seedTemplate(): InspectionTemplate
    {
        /** @var InspectionTemplate $template */
        $template = InspectionTemplate::query()->updateOrCreate(
            ['code' => 'Q7'],
            ['work_package' => 'Pengecoran kolom struktur', 'stage' => InspectionStage::Before],
        );

        // Idempotent without a delete: on a re-run the items are unchanged and,
        // once an inspection has ticked them, a delete would trip the result
        // FK. Seed the butir only the first time the template appears.
        if ($template->items()->exists()) {
            return $template;
        }

        foreach ([
            ['Kebersihan bekisting dan tulangan', 'Bebas kotoran, minyak, dan karat lepas', null],
            ['Selimut beton (beton decking)', 'Sesuai gambar struktur', '± 5 mm'],
            ['Slump beton di lapangan', 'Slump 12 cm', '± 2 cm'],
        ] as $order => [$check, $acceptance, $tolerance]) {
            $template->items()->create([
                'sort_order' => $order + 1,
                'check_text' => $check,
                'acceptance' => $acceptance,
                'tolerance' => $tolerance,
            ]);
        }

        return $template;
    }

    private function seedInspection(Project $project, Location $zone, InspectionTemplate $template): void
    {
        $exists = Inspection::query()
            ->where('project_id', $project->id)
            ->where('template_id', $template->id)
            ->where('location_id', $zone->id)
            ->exists();

        if ($exists) {
            return;
        }

        /** @var Inspection $inspection */
        $inspection = Inspection::query()->create([
            'project_id' => $project->id,
            'location_id' => $zone->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'witness_party' => WitnessParty::Mk,
            'passed' => true,
            'status' => DocumentStatus::Approved,
        ]);

        foreach ($template->items()->orderBy('sort_order')->get() as $item) {
            $inspection->results()->create([
                'template_item_id' => $item->id,
                'result' => ItemResult::Ok,
                'remark' => null,
            ]);
        }

        // Written approved directly: maker-checker needs two people, a seeder is
        // nobody. The runtime submit → approve path (and the NCR block) is
        // covered by tests/Feature/Quality.
        $inspection->approvals()->create([
            'action' => 'approved',
            'user_id' => $this->engineerId(),
            'note' => 'Seluruh butir sesuai; lanjutkan pengecoran.',
        ]);
    }

    private function seedNcr(Project $project, Location $zone): void
    {
        $marker = 'Keropos ringan pada permukaan kolom pasca-bongkar bekisting';

        $exists = Ncr::query()
            ->where('project_id', $project->id)
            ->where('description', $marker)
            ->exists();

        if ($exists) {
            return;
        }

        $subcontractId = Subcontract::query()->value('id');

        if ($subcontractId === null) {
            return; // no subcontractor to hold responsible yet — the XOR needs one
        }

        // Resolved (verified → closed) so the demo carries a complete NCR
        // lifecycle without leaving an OPEN NCR that would block a first
        // handover on the canon project.
        Ncr::query()->create([
            'project_id' => $project->id,
            'location_id' => $zone->id,
            'stage' => InspectionStage::After,
            'description' => $marker,
            'root_cause' => 'Pemadatan (vibrator) kurang merata pada sudut bekisting.',
            'corrective_action' => 'Perbaikan permukaan dengan mortar non-shrink; uji palu beton ulang.',
            'preventive_action' => 'Tambah titik vibrator dan checklist pemadatan per zona.',
            'subcontract_id' => $subcontractId,
            'due_date' => '2026-03-30',
            'verified_by' => $this->engineerId(),
            'verified_at' => '2026-04-02',
            'status' => NcrStatus::Closed,
        ]);
    }

    private function seedConcrete(Project $project, Location $zone): void
    {
        $exists = ConcreteSample::query()
            ->where('project_id', $project->id)
            ->where('truck_no', 'B 9021 KYT')
            ->exists();

        if ($exists) {
            return;
        }

        /** @var ConcreteSample $sample */
        $sample = ConcreteSample::query()->create([
            'project_id' => $project->id,
            'location_id' => $zone->id,
            'pour_date' => '2026-03-16',
            'grade' => 'K-350',
            'slump_cm' => 12.0,
            'truck_no' => 'B 9021 KYT',
            'volume_m3' => 7.0,
            'sample_count' => 6,
        ]);

        $strength = app(ConcreteStrengthService::class);

        // K-350 → target fc' ≈ 28,49 MPa; 7-day ≈ 18,52 target, 28-day = target.
        foreach ([
            [7, 21.5, 'Lab Beton Cakung'],
            [28, 31.2, 'Lab Beton Cakung'],
        ] as [$age, $mpa, $lab]) {
            $sample->tests()->create([
                'age_days' => $age,
                'strength_mpa' => $mpa,
                'lab' => $lab,
                'tested_at' => date('Y-m-d', strtotime("2026-03-16 +{$age} days")),
                'pass' => $strength->passes('K-350', $age, $mpa),
            ]);
        }
    }

    /** The demo drafter/PM login when Iam is seeded; null otherwise. */
    private function engineerId(): ?int
    {
        return User::query()
            ->whereIn('email', ['project-manager@nusantara.test', 'estimator@nusantara.test'])
            ->orderByRaw("case email when 'project-manager@nusantara.test' then 0 else 1 end")
            ->value('id');
    }
}
