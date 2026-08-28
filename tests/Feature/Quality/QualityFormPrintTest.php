<?php

namespace Tests\Feature\Quality;

use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Company;
use Modules\Core\Models\Location;
use Modules\Core\Services\FormPrintService;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Enums\ItemResult;
use Modules\Quality\Enums\NcrStatus;
use Modules\Quality\Enums\WitnessParty;
use Modules\Quality\Models\ConcreteSample;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Models\Ncr;
use Tests\ErpTestCase;

/**
 * P1-QC lane PRINT — the three Quality house forms as REGISTRY entries
 * (PrintableDocuments::quality()), never bespoke composers. The honesty rule:
 * a verdict is printed FROM THE DATABASE or ruled blank, never a plausible
 * default. An unfilled checklist rules HASIL; an open NCR rules its
 * verification; a concrete test prints the STORED, computed pass.
 */
class QualityFormPrintTest extends ErpTestCase
{
    use QualityFixtures;

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);
    }

    private function location(Project $project, array $attributes = []): Location
    {
        return Location::query()->create(array_merge([
            'project_id' => $project->id,
            'kind' => 'floor',
            'code' => 'LT-'.fake()->unique()->numerify('###'),
            'name' => 'Lantai 1 Zona A',
            'sort_order' => 1,
        ], $attributes));
    }

    private function filledInspection(Project $project, InspectionTemplate $template, Location $location, string $secondResult): Inspection
    {
        /** @var Inspection $inspection */
        $inspection = Inspection::query()->create([
            'project_id' => $project->id,
            'location_id' => $location->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'witness_party' => WitnessParty::Mk,
            'passed' => $secondResult !== 'nok',
            'status' => DocumentStatus::Approved,
        ]);

        $items = $template->items()->orderBy('sort_order')->get();
        $inspection->results()->create(['template_item_id' => $items[0]->id, 'result' => ItemResult::Ok]);
        $inspection->results()->create(['template_item_id' => $items[1]->id, 'result' => ItemResult::from($secondResult)]);

        return $inspection;
    }

    // ------------------------------------------------------------- F/QI

    public function test_f_qi_prints_the_filled_checklist_and_the_overall_verdict_from_the_db(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before, ['work_package' => 'Pengecoran kolom struktur']);
        $inspection = $this->filledInspection($project, $template, $this->location($project), 'nok');

        $html = $this->forms->html('inspeksi-mutu', ['id' => $inspection->id]);

        $this->assertStringContainsString('LEMBAR INSPEKSI MUTU', $html);
        $this->assertStringContainsString('Form F/QI', $html);
        $this->assertStringContainsString($inspection->code, $html);
        $this->assertStringContainsString('Pengecoran kolom struktur', $html);
        $this->assertStringContainsString('Sebelum pelaksanaan', $html);
        // The butir and their verdicts, as recorded.
        $this->assertStringContainsString('Kebersihan bekisting', $html);
        $this->assertStringContainsString('Tidak sesuai', $html);
        // Overall verdict from the stored boolean — one nok fails the sheet.
        $this->assertStringContainsString('TIDAK LULUS', $html);
    }

    public function test_f_qi_with_no_results_rules_the_verdict_cell_never_claims_lulus(): void
    {
        $project = $this->project();
        $template = $this->template(InspectionStage::Before);

        /** @var Inspection $inspection */
        $inspection = Inspection::query()->create([
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'template_id' => $template->id,
            'inspected_at' => '2026-03-16',
            'passed' => true, // only vacuously true — no butir ticked
            'status' => DocumentStatus::Draft,
        ]);

        $html = $this->forms->html('inspeksi-mutu', ['id' => $inspection->id]);

        // An unfilled checklist must NOT print a pass off a vacuous boolean.
        $this->assertStringNotContainsString('LULUS', $html);
    }

    // ------------------------------------------------------------- F/NCR

    public function test_f_ncr_prints_the_report_and_names_the_responsible_party(): void
    {
        $project = $this->project();
        $employee = $this->employee(['name' => 'Agus Prasetyo']);

        $ncr = Ncr::query()->create([
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'stage' => InspectionStage::During,
            'description' => 'Bekisting kolom tidak tegak lurus',
            'root_cause' => 'Penyetelan sabuk bekisting kurang',
            'responsible_employee_id' => $employee->id,
            'status' => NcrStatus::Open,
        ]);

        $html = $this->forms->html('ketidaksesuaian', ['id' => $ncr->id]);

        $this->assertStringContainsString('LAPORAN KETIDAKSESUAIAN', $html);
        $this->assertStringContainsString('Form F/NCR', $html);
        $this->assertStringContainsString($ncr->code, $html);
        $this->assertStringContainsString('Bekisting kolom tidak tegak lurus', $html);
        $this->assertStringContainsString('Agus Prasetyo', $html);
        $this->assertStringContainsString('Terbuka', $html); // NcrStatus label
    }

    public function test_f_ncr_open_report_leaves_the_verification_ruled(): void
    {
        $project = $this->project();

        $ncr = Ncr::query()->create([
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'stage' => InspectionStage::Before,
            'description' => 'Ketidaksesuaian yang belum diverifikasi',
            'subcontract_id' => $this->subcontract()->id,
            'status' => NcrStatus::Open,
            // verified_by / verified_at deliberately null
        ]);

        $html = $this->forms->html('ketidaksesuaian', ['id' => $ncr->id]);

        // No verifier is invented for an unverified NCR.
        $this->assertStringContainsString('DIVERIFIKASI OLEH', $html);
        $this->assertStringNotContainsString('Ir. Sinta Melati', $html);
    }

    // ------------------------------------------------------------- F/BU

    public function test_f_bu_prints_the_target_and_the_stored_pass_verdicts(): void
    {
        $project = $this->project();

        /** @var ConcreteSample $sample */
        $sample = ConcreteSample::query()->create([
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'pour_date' => '2026-03-16',
            'grade' => 'K-350',
            'slump_cm' => 12,
            'truck_no' => 'B 9021 KYT',
            'volume_m3' => 7,
            'sample_count' => 6,
        ]);
        $sample->tests()->create(['age_days' => 7, 'strength_mpa' => 21.5, 'lab' => 'Lab Cakung', 'pass' => true]);
        $sample->tests()->create(['age_days' => 28, 'strength_mpa' => 26.0, 'lab' => 'Lab Cakung', 'pass' => false]);

        $html = $this->forms->html('benda-uji-beton', ['id' => $sample->id]);

        $this->assertStringContainsString('BENDA UJI BETON', $html);
        $this->assertStringContainsString('Form F/BU', $html);
        $this->assertStringContainsString('K-350', $html);
        // The 28-day fc' target the grade means.
        $this->assertStringContainsString('28,49 MPa', $html);
        // The stored verdicts, both directions.
        $this->assertStringContainsString('MEMENUHI', $html);
        $this->assertStringContainsString('TIDAK MEMENUHI', $html);
    }
}
