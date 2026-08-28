<?php

namespace Tests\Feature\Core;

use InvalidArgumentException;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Models\OvertimePermit;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WorkPermit;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Formulir QC dan izin — the owner's punch-list register and the three site
 * permits, printed in the house format.
 *
 * Two very different kinds of sheet share this file on purpose, because the
 * honesty rule pulls in opposite directions on each and the pair is what proves
 * it is a rule rather than a habit:
 *
 *   DAFTAR TEMUAN is a register the ERP genuinely owns. prj_defects holds every
 *   column the paper asks for, so every cell is printed from the database and
 *   the summary band is DefectService::summary() — the same counts the BAST II
 *   gate reads, not a second tally computed here that could drift from it.
 *
 *   IZIN KERJA / IZIN LEMBUR / IZIN MASUK-KELUAR MATERIAL were blank pads
 *   until P0-C and are real documents now (prj_work_permits /
 *   prj_overtime_permits / prj_gate_passes), each sheet anchored on ITS OWN
 *   row. The blank-pad pins that used to live here — undated sheets, no
 *   signatory ever printed, "Formulir ini dicetak kosong" — were REPLACED with
 *   the new contract, not kept alongside it: the behaviour they pinned is the
 *   one the paket removes. Body content per permit is covered in
 *   tests/Feature/Projects/{WorkPermit,OvertimePermit,GatePass}Test; what this
 *   file keeps is the shared frame — the permit sheet still carries the house
 *   letterhead, and it is dated from its own row, not from print-day.
 */
class QcFormPrintTest extends ErpTestCase
{
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

    private function project(array $attributes = []): Project
    {
        $customer = Customer::query()->create([
            'name' => 'PT Angkasa Pura I (Persero)',
            'is_pkp' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        $contract = Contract::query()->create([
            'customer_id' => $customer->id,
            'title' => 'Pengembangan Bandar Udara Sultan Hasanudin',
            'scope_type' => 'construction',
            'value' => 48_500_000_000,
            'ppn_rate' => 11.0,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'contract_number_customer' => 'SPK/AP1/2025/XII/0142',
            'sign_date' => '2025-12-18',
            'status' => 'approved',
        ]);

        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-001',
            'name' => 'Pengembangan Bandar Udara Sultan Hasanudin - Makassar',
            'contract_id' => $contract->id,
            'customer_id' => $customer->id,
            'type' => 'construction',
            'status' => 'active',
            'location' => 'Jl. Bandara Baru, Mandai',
            'city' => 'Makassar',
            'province' => 'Sulawesi Selatan',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contract_value' => 48_500_000_000,
            'retention_pct' => 5.0,
            'warranty_months' => 12,
            'consultant_name' => 'PT Jaya CM',
        ], $attributes));
    }

    /**
     * Written straight through the model rather than DefectService::create,
     * which announces every critical finding to everyone holding prj.update —
     * a register test has no business ringing bells.
     */
    private function defect(Project $project, array $attributes = []): Defect
    {
        return Defect::query()->create(array_merge([
            'project_id' => $project->id,
            'title' => 'Retak rambut pada dinding',
            'severity' => 'minor',
            'source' => 'internal',
            'status' => 'open',
            'reported_on' => '2026-06-01',
        ], $attributes));
    }

    private function employee(string $name, string $position, string $nik): Employee
    {
        return Employee::query()->create([
            'code' => 'EMP-'.substr($nik, -4),
            'name' => $name,
            'nik_ktp' => $nik,
            'gender' => 'male',
            'birth_date' => '1985-04-11',
            'ptkp_status' => 'K/1',
            'join_date' => '2020-02-01',
            'employment_type' => 'tetap',
            'position' => $position,
            'department' => 'proyek',
            'base_salary' => 18_000_000,
        ]);
    }

    // ===================================================== daftar temuan

    public function test_the_register_prints_every_finding_on_the_project(): void
    {
        $project = $this->project();
        $kritis = $this->defect($project, [
            'title' => 'Lift tidak level di lantai 5',
            'location' => 'Zona B, lantai 5',
            'severity' => 'critical',
            'source' => 'handover',
            'due_date' => '2026-06-20',
        ]);
        $minor = $this->defect($project, ['title' => 'Sealant jendela belum rapi']);

        $html = $this->forms->html('daftar-temuan', ['id' => $project->id]);

        $this->assertStringContainsString('DAFTAR TEMUAN', $html);
        $this->assertStringContainsString($kritis->code, $html);
        $this->assertStringContainsString($minor->code, $html);
        $this->assertStringContainsString('Lift tidak level di lantai 5', $html);
        $this->assertStringContainsString('Zona B, lantai 5', $html);
        $this->assertStringContainsString('Sealant jendela belum rapi', $html);
        // The paper's own vocabulary, not the enum's explanatory label.
        $this->assertStringContainsString('>Kritis<', $html);
        $this->assertStringContainsString('20 Juni 2026', $html, 'tenggat perbaikan');
    }

    /**
     * The register is a project document and gets signed as one. A finding from
     * the job next door appearing on it is not a cosmetic bug — it is a defect
     * charged against the wrong retensi.
     */
    public function test_the_register_leaves_out_another_projects_findings(): void
    {
        $project = $this->project();
        $other = $this->project(['code' => 'PRJ-2026-002', 'name' => 'Instalasi ELV Bank Artha']);

        $mine = $this->defect($project, ['title' => 'Retak pada kolom K3']);
        $theirs = $this->defect($other, ['title' => 'Kabel UTP belum dilabeli']);

        $html = $this->forms->html('daftar-temuan', ['id' => $project->id]);

        $this->assertStringContainsString($mine->code, $html);
        $this->assertStringNotContainsString($theirs->code, $html);
        $this->assertStringNotContainsString('Kabel UTP belum dilabeli', $html);
    }

    public function test_the_status_filter_narrows_the_printed_rows(): void
    {
        $project = $this->project();
        $open = $this->defect($project, ['title' => 'Pintu tidak menutup rapat']);
        $closed = $this->defect($project, [
            'title' => 'Cat plafon mengelupas',
            'status' => 'closed',
            'fixed_at' => '2026-06-10',
            'verified_at' => '2026-06-12',
        ]);

        $terbuka = $this->forms->html('daftar-temuan', ['id' => $project->id, 'status' => 'open']);
        $this->assertStringContainsString($open->code, $terbuka);
        $this->assertStringNotContainsString($closed->code, $terbuka);
        $this->assertStringNotContainsString('Cat plafon mengelupas', $terbuka);

        $selesai = $this->forms->html('daftar-temuan', ['id' => $project->id, 'status' => 'closed']);
        $this->assertStringContainsString($closed->code, $selesai);
        // The TITLE, not the code: the recap band still names the oldest OPEN
        // finding by code on a page filtered to closed ones, and that is the
        // point of a band that counts the whole register — a sheet showing only
        // finished work must not read as a finished punch list.
        $this->assertStringNotContainsString('Pintu tidak menutup rapat', $selesai);
        $this->assertStringContainsString('12 Juni 2026', $selesai, 'tanggal verifikasi');
    }

    /**
     * A status nobody defined must not quietly print an empty register — an
     * empty register is exactly what somebody hoping to clear a punch list
     * would like the sheet to say.
     */
    public function test_an_unknown_status_filter_is_refused_rather_than_printing_nothing(): void
    {
        $project = $this->project();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status temuan tidak dikenal: selesai-lah.');

        $this->forms->html('daftar-temuan', ['id' => $project->id, 'status' => 'selesai-lah']);
    }

    /**
     * The band counts the WHOLE register even when the page is filtered, and it
     * counts it with DefectService::summary() — the same numbers the BAST II
     * gate refuses on. A second tally computed in the printing layer is a
     * second answer to "what is still open", and the two would drift.
     */
    public function test_the_summary_band_counts_the_whole_register_not_the_filtered_page(): void
    {
        $project = $this->project();
        $this->defect($project, ['severity' => 'critical', 'title' => 'Panel bocor']);
        $this->defect($project, ['severity' => 'major', 'title' => 'Waterproofing atap']);
        $this->defect($project, ['severity' => 'minor', 'title' => 'Sealant']);
        $this->defect($project, [
            'severity' => 'minor',
            'title' => 'Nat keramik',
            'status' => 'closed',
            'verified_at' => '2026-06-12',
        ]);

        $html = $this->forms->html('daftar-temuan', ['id' => $project->id, 'status' => 'open']);

        $this->assertStringContainsString('<b>Total temuan</b> : 4', $html);
        $this->assertStringContainsString('<b>Masih terbuka</b> : 3', $html);
        // What actually stops the serah terima: critical + major still open.
        $this->assertStringContainsString('<b>Penahan BAST II</b> : 2', $html);
        $this->assertStringContainsString('<b>Kritis</b> : 1', $html);
        $this->assertStringContainsString('<b>Mayor</b> : 1', $html);
        $this->assertStringContainsString('<b>Minor</b> : 2', $html);
        // …and the sheet says out loud that the rows below are a subset.
        $this->assertStringContainsString('Disaring menurut status', $html);
        $this->assertStringContainsString('Terbuka', $html);
    }

    public function test_an_empty_register_says_so_instead_of_printing_an_empty_table(): void
    {
        $project = $this->project();

        $html = $this->forms->html('daftar-temuan', ['id' => $project->id]);

        $this->assertStringContainsString('Tidak ada temuan', $html);
        $this->assertStringContainsString('<b>Total temuan</b> : 0', $html);
    }

    /** Twelve columns do not fit on a portrait page. */
    public function test_the_register_prints_landscape(): void
    {
        $html = $this->forms->html('daftar-temuan', ['id' => $this->project()->id]);

        $this->assertStringContainsString('<body class="landscape">', $html);
    }

    /**
     * A repair past its target that nobody closed out is the one thing a punch
     * list is read for. The flag comes from Defect::isOverdue() — the same
     * method the list screen and the stat card use — so the sheet cannot say
     * "lewat" about a row the screen calls on time.
     */
    public function test_a_repair_past_its_target_is_marked_on_the_sheet(): void
    {
        $project = $this->project();
        $this->defect($project, ['title' => 'Bocor talang', 'due_date' => '2020-01-01']);

        $html = $this->forms->html('daftar-temuan', ['id' => $project->id]);

        $this->assertStringContainsString('lewat tenggat', $html);
        $this->assertStringContainsString('<b>Lewat tenggat</b> : 1', $html);
    }

    // ================================================== izin (P0-C: dari baris)

    /**
     * Each provider row builds ITS OWN permit and hands back the id the sheet
     * is anchored on — the P0-C anchoring, permit id, not project id.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function permitProvider(): array
    {
        return [
            'izin kerja' => ['izin-kerja', 'IZIN KERJA LAPANGAN', 'workPermit'],
            'izin lembur' => ['izin-lembur', 'IZIN KERJA LEMBUR', 'overtimePermit'],
            // No ampersand in the expectation: the sheet's title really is
            // "MATERIAL & PERALATAN" and Blade escapes it to &amp;.
            'izin material' => ['izin-material', 'IZIN MASUK / KELUAR MATERIAL', 'gatePass'],
        ];
    }

    private function workPermit(Project $project): int
    {
        return WorkPermit::query()->create([
            'project_id' => $project->id,
            'permit_date' => '2026-06-15',
            'shift' => 'pagi',
            'work_description' => 'Pengecoran kolom lantai 3',
            'valid_from' => '2026-06-15 08:00:00',
            'valid_until' => '2026-06-15 17:00:00',
            'requested_by' => $this->employee('Sutrisno Hadi', 'Mandor Sipil', '3216012504780001')->id,
            'status' => 'draft',
        ])->id;
    }

    private function overtimePermit(Project $project): int
    {
        return OvertimePermit::query()->create([
            'project_id' => $project->id,
            'overtime_date' => '2026-06-15',
            'start_time' => '18:00',
            'end_time' => '21:00',
            'reason' => 'Kejar target pengecoran',
            'status' => 'draft',
        ])->id;
    }

    private function gatePass(Project $project): int
    {
        return GatePass::query()->create([
            'project_id' => $project->id,
            'direction' => 'in',
            'pass_date' => '2026-06-15',
            'status' => 'draft',
        ])->id;
    }

    /**
     * The shared frame survives the P0-C rewrite: every permit sheet still
     * carries the four-party band and the contract identity block, now around
     * a body printed from the permit's own rows (which the three
     * tests/Feature/Projects permit suites cover cell by cell).
     */
    #[DataProvider('permitProvider')]
    public function test_a_permit_sheet_carries_the_project_header(string $form, string $title, string $builder): void
    {
        $html = $this->forms->html($form, ['id' => $this->{$builder}($this->project())]);

        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString('PENGEMBANGAN BANDAR UDARA SULTAN HASANUDIN - MAKASSAR', $html);
        $this->assertStringContainsString('PT Angkasa Pura I (Persero)', $html);
        $this->assertStringContainsString('PT Jaya CM', $html);
        $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
        $this->assertStringContainsString('SPK/AP1/2025/XII/0142', $html);
        $this->assertStringContainsString('365 hari kalender', $html);
    }

    /**
     * The sheet is dated from ITS OWN row. The blank-pad era printed undated
     * sheets so a pad printed Monday could serve all month; a permit is one
     * dated document now, and HARI KE counts from the permit's date, never
     * from whichever day somebody pressed print.
     */
    #[DataProvider('permitProvider')]
    public function test_a_permit_sheet_is_dated_from_its_own_row(string $form, string $title, string $builder): void
    {
        $html = $this->forms->html($form, ['id' => $this->{$builder}($this->project())]);

        $this->assertStringContainsString('15 Juni 2026', $html);
        $this->assertStringContainsString('18 Desember 2025', $html, 'tanggal SPK still prints');
        // 2026-06-15 is day 166 of a job that started 2026-01-01.
        $this->assertStringContainsString('166', $html);
        // …and the honest sentence of the blank-pad era is gone WITH the pads.
        $this->assertStringNotContainsString('dicetak kosong', $html);
        $this->assertStringNotContainsString('belum menyimpan data', $html);
    }

    /**
     * The sheet says what the permit IS. A draft printed before anyone
     * approved it is exactly the sheet somebody might wave at a gate, so the
     * NO. IZIN line carries the status in so many words — "STATUS: Draf" — and
     * never the word Disetujui until the approval actually happened. Printed
     * from the row or printed as a rule was the P0-A rule; printed AS ITS OWN
     * STATUS is this paket's extension of it.
     */
    #[DataProvider('permitProvider')]
    public function test_an_unapproved_permit_prints_its_status_instead_of_pretending(string $form, string $title, string $builder): void
    {
        $id = $this->{$builder}($this->project());

        $draft = $this->forms->html($form, ['id' => $id]);
        $this->assertStringContainsString('STATUS: Draf', $draft);
        $this->assertStringNotContainsString('Disetujui', $draft);

        $model = match ($builder) {
            'workPermit' => WorkPermit::class,
            'overtimePermit' => OvertimePermit::class,
            'gatePass' => GatePass::class,
        };
        $model::query()->whereKey($id)->update(['status' => 'approved']);

        $approved = $this->forms->html($form, ['id' => $id]);
        $this->assertStringContainsString('STATUS: Disetujui', $approved);
    }

    /**
     * The Diperiksa column carries the one claim only the gate can make. An
     * approved-but-unchecked pass prints its ruled blank there and in the JAM
     * cell; only the periksa stamp (checked_by + checked_at) puts the guard's
     * name and the clock time on paper — "checked" is exactly what that column
     * asserts, and the sheet must not assert it early.
     */
    public function test_the_diperiksa_column_stays_blank_until_the_gate_stamps_the_pass(): void
    {
        $guard = $this->adminUser();
        $id = $this->gatePass($this->project());
        GatePass::query()->whereKey($id)->update(['status' => 'approved']);

        $unchecked = $this->forms->html('izin-material', ['id' => $id]);
        $this->assertStringNotContainsString($guard->name, $unchecked);
        $this->assertStringNotContainsString('(diperiksa)', $unchecked);

        GatePass::query()->whereKey($id)->update([
            'checked_by' => $guard->id,
            'checked_at' => '2026-06-15 14:30:00',
        ]);

        $checked = $this->forms->html('izin-material', ['id' => $id]);
        $this->assertStringContainsString($guard->name, $checked);
        $this->assertStringContainsString('14:30 (diperiksa)', $checked);
    }

    // ---------------------------------------------------------- the endpoint

    public function test_the_endpoint_serves_the_register_as_printable_html(): void
    {
        $project = $this->project();
        $defect = $this->defect($project, ['title' => 'Panel bocor', 'severity' => 'critical']);

        $response = $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/daftar-temuan/{$project->id}")
            ->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString($defect->code, $response->getContent());
    }

    public function test_the_permit_endpoint_needs_the_permission_to_see_the_permit(): void
    {
        $permitId = $this->workPermit($this->project());

        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo('prj.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->refresh())
            ->get("/api/core/print/forms/izin-kerja/{$permitId}")
            ->assertForbidden();
    }
}
