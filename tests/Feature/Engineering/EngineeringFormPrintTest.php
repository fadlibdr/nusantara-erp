<?php

namespace Tests\Feature\Engineering;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Company;
use Modules\Core\Models\Location;
use Modules\Core\Services\FormPrintService;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\IppDrawing;
use Modules\Engineering\Models\IppEquipment;
use Modules\Engineering\Models\IppMaterial;
use Modules\Engineering\Models\IppMaterialApproval;
use Modules\Engineering\Models\Transmittal;
use Modules\Engineering\Models\TransmittalLine;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Spatie\Permission\Models\Permission;
use Tests\ErpTestCase;

/**
 * P1-ENG lane PRINT — the four Engineering house forms as REGISTRY entries
 * (PrintableDocuments::engineering()), never bespoke composers.
 *
 * The one thing these sheets must never do is STAMP FOR THE MK. The decision
 * column is typed in from the sheet the MK returned (recorded-fact columns on
 * the submittal), so the print reads decision/decided_at/notes off the row —
 * and a submittal the MK has not answered prints its waiting state honestly,
 * not a plausible stamp and not an inviting blank next to the word KEPUTUSAN.
 * A superseded revision says so on its face: a reprint of R0 that looks
 * current is a sheet somebody builds from.
 */
class EngineeringFormPrintTest extends ErpTestCase
{
    use EngineeringFixtures;

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

    // ------------------------------------------------------------- F/SD

    public function test_f_sd_prints_the_recorded_mk_decision_from_the_db(): void
    {
        $project = $this->project();
        $submittal = $this->decidedSubmittal($project, 'approved_as_noted');

        $html = $this->forms->html('persetujuan-gambar', ['id' => $submittal->id]);

        $this->assertStringContainsString('LEMBAR PERSETUJUAN SHOP DRAWING', $html);
        $this->assertStringContainsString('Form F/SD', $html);
        $this->assertStringContainsString($submittal->code, $html);
        $this->assertStringContainsString('GPC-ST-101', $html);
        $this->assertStringContainsString('Denah Pondasi Bore Pile', $html);
        // The stamp exactly as recorded — four values, this one is the second.
        $this->assertStringContainsString('Disetujui dengan catatan', $html);
        $this->assertStringContainsString('12 Maret 2026', $html);
        $this->assertStringContainsString('Perbaiki notasi tulangan kolom K1.', $html);
        // The four-party band resolved through drawing.project.
        $this->assertStringContainsString('KONTRAKTOR', $html);
        $this->assertStringContainsString('Gedung Perkantoran Cikarang', $html);
    }

    public function test_f_sd_null_decision_prints_the_waiting_state_never_a_stamp(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);
        $submittal = $this->submittal($drawing);

        $html = $this->forms->html('persetujuan-gambar', ['id' => $submittal->id]);

        // Honest state, from decision IS NULL + reviewer_party — not a stamp.
        $this->assertStringContainsString('Menunggu keputusan Konsultan MK', $html);
        $this->assertStringNotContainsString('Disetujui', $html);
        $this->assertStringNotContainsString('Ditolak', $html);
    }

    public function test_f_sd_marks_a_superseded_revision_on_its_face(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);
        $r0 = $this->submittal($drawing);
        $r1 = $this->submittal($drawing, ['revision' => 'R1', 'submitted_at' => '2026-03-20']);

        // R1 superseded R0 (DrawingSubmittalService did this on create).
        $r0->refresh();
        $this->assertNotNull($r0->superseded_at, 'Fixture drift: R1 no longer supersedes R0.');

        $html = $this->forms->html('persetujuan-gambar', ['id' => $r0->id]);

        $this->assertStringContainsString('DIGANTIKAN', $html);
        $this->assertStringContainsString($r1->code, $html);
    }

    // ------------------------------------------------------------- F/SM

    public function test_f_sm_prints_the_material_and_its_recorded_decision(): void
    {
        $project = $this->project();
        $submittal = $this->materialSubmittal($project);
        $submittal->forceFill([
            'decision' => 'approved',
            'decided_at' => '2026-03-15',
            'notes' => 'Sertifikat pabrik lengkap.',
        ])->save();

        $html = $this->forms->html('persetujuan-material', ['id' => $submittal->id]);

        $this->assertStringContainsString('LEMBAR PERSETUJUAN MATERIAL', $html);
        $this->assertStringContainsString('Form F/SM', $html);
        $this->assertStringContainsString($submittal->code, $html);
        $this->assertStringContainsString('Besi Beton Ulir D16', $html);
        $this->assertStringContainsString('Krakatau Steel', $html);
        $this->assertStringContainsString('SNI 2052:2017', $html);
        $this->assertStringContainsString('Disetujui', $html);
        $this->assertStringContainsString('15 Maret 2026', $html);
    }

    public function test_f_sm_null_decision_prints_the_waiting_state(): void
    {
        $project = $this->project();
        $submittal = $this->materialSubmittal($project);

        $html = $this->forms->html('persetujuan-material', ['id' => $submittal->id]);

        $this->assertStringContainsString('Menunggu keputusan Konsultan MK', $html);
        $this->assertStringNotContainsString('Disetujui', $html);
    }

    // ------------------------------------------------------------- F/TR

    public function test_f_tr_prints_its_lines_and_the_recorded_receipt(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);
        $sds = $this->submittal($drawing);

        $transmittal = Transmittal::query()->create([
            'project_id' => $project->id,
            'direction' => 'keluar',
            'to_party' => 'PT Mitra Konsultan Konstruksi (MK)',
            'transmittal_date' => '2026-03-06',
            'notes' => 'Mohon diperiksa dalam 7 hari kerja.',
            'received_by' => 'Ir. Ratna Dewi',
            'received_at' => '2026-03-07 09:30:00',
            'created_by' => $this->admin()->id,
        ]);
        TransmittalLine::query()->create([
            'transmittal_id' => $transmittal->id,
            'document_type' => DrawingSubmittal::class,
            'document_id' => $sds->id,
            'description' => 'Shop drawing pondasi',
            'remarks' => '3 salinan',
        ]);
        TransmittalLine::query()->create([
            'transmittal_id' => $transmittal->id,
            'description' => 'Metode kerja bore pile (dokumen fisik)',
        ]);

        $html = $this->forms->html('transmittal', ['id' => $transmittal->id]);

        $this->assertStringContainsString('TRANSMITTAL DOKUMEN', $html);
        $this->assertStringContainsString('Form F/TR', $html);
        $this->assertStringContainsString($transmittal->code, $html);
        $this->assertStringContainsString('PT Mitra Konsultan Konstruksi (MK)', $html);
        $this->assertStringContainsString($sds->code, $html);
        $this->assertStringContainsString('Metode kerja bore pile (dokumen fisik)', $html);
        $this->assertStringContainsString('3 salinan', $html);
        // The tanda-terima column really records who signed — so it prints.
        $this->assertStringContainsString('Ir. Ratna Dewi', $html);
    }

    public function test_f_tr_unreceived_rules_the_receipt_cells_blank(): void
    {
        $project = $this->project();

        $transmittal = Transmittal::query()->create([
            'project_id' => $project->id,
            'direction' => 'masuk',
            'to_party' => 'Kontraktor Pelaksana',
            'transmittal_date' => '2026-03-06',
            'created_by' => $this->admin()->id,
        ]);
        TransmittalLine::query()->create([
            'transmittal_id' => $transmittal->id,
            'description' => 'Gambar kontrak revisi owner',
        ]);

        $html = $this->forms->html('transmittal', ['id' => $transmittal->id]);

        // Nobody signed yet: no invented receiver, no invented date.
        $this->assertStringNotContainsString('Ir. Ratna Dewi', $html);
        $this->assertStringContainsString('Belum diterima', $html);
    }

    // ------------------------------------------------------------- F/IPP

    public function test_f_ipp_prints_the_four_line_tables_from_the_db(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);
        $sds = $this->decidedSubmittal($project, 'approved', $drawing);
        $sms = $this->materialSubmittal($project);
        $sms->forceFill(['decision' => 'approved_as_noted', 'decided_at' => '2026-03-15'])->save();

        $task = WbsTask::query()->create([
            'project_id' => $project->id,
            'wbs_code' => 'B.3',
            'name' => 'Pekerjaan Pembesian Pondasi',
            'weight_pct' => 0,
            'progress_pct' => 0,
        ]);
        $zone = Location::query()->create([
            'project_id' => $project->id,
            'kind' => 'zone',
            'code' => 'GPC-ZA',
            'name' => 'Zona A',
            'sort_order' => 1,
        ]);

        $ipp = WorkPermitIpp::query()->create([
            'project_id' => $project->id,
            'scope' => 'struktur',
            'location_id' => $zone->id,
            'wbs_task_id' => $task->id,
            'description' => 'Pengecoran pondasi bore pile Zona A',
            'planned_start' => '2026-03-23',
            'duration_days' => 14,
            'status' => DocumentStatus::Draft,
        ]);
        IppMaterial::query()->create([
            'ipp_id' => $ipp->id,
            'description' => 'Ready Mix K-300',
            'qty' => 120,
            'unit' => 'm3',
        ]);
        IppEquipment::query()->create([
            'ipp_id' => $ipp->id,
            'description' => 'Concrete pump 36 m',
            'qty' => 1,
            'notes' => 'Standby 2 hari',
        ]);
        IppDrawing::query()->create([
            'ipp_id' => $ipp->id,
            'drawing_submittal_id' => $sds->id,
        ]);
        IppMaterialApproval::query()->create([
            'ipp_id' => $ipp->id,
            'material_submittal_id' => $sms->id,
        ]);

        $html = $this->forms->html('ijin-pelaksanaan', ['id' => $ipp->id]);

        $this->assertStringContainsString('IJIN PELAKSANAAN PEKERJAAN', $html);
        $this->assertStringContainsString('Form F/IPP', $html);
        $this->assertStringContainsString($ipp->code, $html);
        // The work package — a real column (wbs_task_id), code and name.
        $this->assertStringContainsString('B.3', $html);
        $this->assertStringContainsString('Pekerjaan Pembesian Pondasi', $html);
        $this->assertStringContainsString('Zona A', $html);
        // The four tables, each from its own rows.
        $this->assertStringContainsString('Ready Mix K-300', $html);
        $this->assertStringContainsString('Concrete pump 36 m', $html);
        $this->assertStringContainsString($sds->code, $html);
        $this->assertStringContainsString($sms->code, $html);
        // The stamps as recorded: the drawing's clean approval, and the
        // material's approved-as-noted printed AS approved-as-noted — the
        // sheet records stamps, the gate (IppService) weighs them.
        $this->assertStringContainsString('Disetujui', $html);
        $this->assertStringContainsString('Disetujui dengan catatan', $html);
    }

    public function test_f_ipp_undecided_lines_print_their_waiting_state(): void
    {
        $project = $this->project();
        $drawing = $this->drawing($project);
        $sds = $this->submittal($drawing);

        $ipp = WorkPermitIpp::query()->create([
            'project_id' => $project->id,
            'scope' => 'struktur',
            'description' => 'Pengecoran pondasi bore pile Zona A',
            'planned_start' => '2026-03-23',
            'duration_days' => 14,
            'status' => DocumentStatus::Draft,
        ]);
        IppDrawing::query()->create([
            'ipp_id' => $ipp->id,
            'drawing_submittal_id' => $sds->id,
        ]);

        $html = $this->forms->html('ijin-pelaksanaan', ['id' => $ipp->id]);

        $this->assertStringContainsString('Menunggu keputusan', $html);
        $this->assertStringNotContainsString('Disetujui dengan catatan', $html);
    }

    // ------------------------------------------------------- the endpoint

    public function test_printing_an_engineering_form_needs_eng_view(): void
    {
        $project = $this->project();
        $submittal = $this->materialSubmittal($project);

        $outsider = User::factory()->create();
        $outsider->givePermissionTo(Permission::findOrCreate('prj.view', 'web'));

        $this->actingAs($outsider->refresh())
            ->get("/api/core/print/forms/persetujuan-material/{$submittal->id}")
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get("/api/core/print/forms/persetujuan-material/{$submittal->id}")
            ->assertOk();
    }

    // ---------------------------------------------------------- fixtures

    /** A decided SDS on its own drawing, decision as named. */
    private function decidedSubmittal(Project $project, string $decision, $drawing = null): DrawingSubmittal
    {
        $submittal = $this->submittal($drawing ?? $this->drawing($project));

        $submittal->forceFill([
            'decision' => $decision,
            'decided_at' => '2026-03-12',
            'notes' => 'Perbaiki notasi tulangan kolom K1.',
        ])->save();

        return $submittal->refresh();
    }
}
