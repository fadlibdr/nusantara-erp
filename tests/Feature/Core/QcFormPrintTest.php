<?php

namespace Tests\Feature\Core;

use InvalidArgumentException;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Defect;
use Modules\Projects\Models\Project;
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
 *   IZIN KERJA / IZIN LEMBUR / IZIN MASUK-KELUAR MATERIAL have no table
 *   anywhere in this ERP. Not a partial one — none. So the body of all three is
 *   ruled blanks, and the only thing the computer contributes is the letterhead
 *   the site currently photocopies and the identity block it currently writes
 *   out by hand. The tests below pin BOTH halves: that the header really is
 *   filled, and that nothing else is.
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

    // ======================================================= izin (blank)

    public static function permitProvider(): array
    {
        return [
            'izin kerja' => ['izin-kerja', 'IZIN KERJA LAPANGAN'],
            'izin lembur' => ['izin-lembur', 'IZIN KERJA LEMBUR'],
            // No ampersand in the expectation: the sheet's title really is
            // "MATERIAL & PERALATAN" and Blade escapes it to &amp;.
            'izin material' => ['izin-material', 'IZIN MASUK / KELUAR MATERIAL'],
        ];
    }

    /**
     * The half the ERP can honestly contribute: the letterhead and the contract
     * identity the site currently writes out by hand on every sheet.
     */
    #[DataProvider('permitProvider')]
    public function test_a_permit_form_carries_the_project_header(string $form, string $title): void
    {
        $html = $this->forms->html($form, ['id' => $this->project()->id]);

        $this->assertStringContainsString($title, $html);
        $this->assertStringContainsString('PENGEMBANGAN BANDAR UDARA SULTAN HASANUDIN - MAKASSAR', $html);
        $this->assertStringContainsString('PT Angkasa Pura I (Persero)', $html);
        $this->assertStringContainsString('PT Jaya CM', $html);
        $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
        $this->assertStringContainsString('SPK/AP1/2025/XII/0142', $html);
        $this->assertStringContainsString('365 hari kalender', $html);
    }

    /**
     * …and the half it cannot. Said in plain Indonesian on the sheet itself,
     * because the clerk holding it has to know that filing the paper IS the
     * record — there is nowhere in the ERP for it to go afterwards.
     */
    #[DataProvider('permitProvider')]
    public function test_a_permit_form_says_plainly_that_it_is_a_printable_blank(string $form): void
    {
        $html = $this->forms->html($form, ['id' => $this->project()->id]);

        $this->assertStringContainsString('Formulir ini dicetak kosong', $html);
        $this->assertStringContainsString('belum menyimpan data', $html);
        $this->assertStringContainsString('lembar kertas', $html);
    }

    /**
     * A pad of fifty permits printed on a Monday and used across the month must
     * not all claim to be Monday's. The day-dependent lines are ruled blanks
     * unless the caller names a date; the CONTRACT lines, which do not move,
     * stay filled.
     */
    #[DataProvider('permitProvider')]
    public function test_a_blank_permit_is_undated_unless_a_date_is_asked_for(string $form): void
    {
        $project = $this->project();

        $blank = $this->forms->html($form, ['id' => $project->id]);

        // PERIODE, TANGGAL, MINGGU KE, HARI KE, SISA HARI + the two
        // PERPANJANGAN WAKTU lines nothing in this ERP records. The bare
        // `<span class="fill-line"></span>` is precisely the identity block's
        // markup — every rule a form draws in its own body carries an explicit
        // min-width, so this counts the identity block and only that.
        $this->assertSame(7, substr_count($blank, '<span class="fill-line"></span>'));
        $this->assertStringContainsString('18 Desember 2025', $blank, 'tanggal SPK does not move');

        $dated = $this->forms->html($form, ['id' => $project->id, 'date' => '2026-06-15']);

        $this->assertSame(2, substr_count($dated, '<span class="fill-line"></span>'));
        $this->assertStringContainsString('15 Juni 2026', $dated);
    }

    /**
     * Nobody in this ERP is recorded as the applicant, the K3 officer, the
     * storeman or the security guard on a given shift. The roles print; the
     * names do not, and in particular the site manager is not quietly promoted
     * into whichever box happens to be free.
     */
    #[DataProvider('permitProvider')]
    public function test_a_permit_form_invents_no_signatory(string $form): void
    {
        $project = $this->project([
            'project_manager_id' => $this->employee('Rina Wijaya', 'Project Manager', '3273010101850001')->id,
            'site_manager_id' => $this->employee('Budi Santoso', 'Site Manager', '3273010101850002')->id,
        ]);

        $html = $this->forms->html($form, ['id' => $project->id]);

        $this->assertStringNotContainsString('Budi Santoso', $html);
        $this->assertStringNotContainsString('Rina Wijaya', $html);
        // Three rules, nothing above any of them — the layout prints
        // `<div class="sig-name">{name}&nbsp;</div>` and a filled column would
        // put the name inside it.
        $this->assertSame(3, substr_count($html, '<div class="sig-name">&nbsp;</div>'));
    }

    public function test_izin_kerja_rules_blank_rows_for_equipment_and_hazards(): void
    {
        $html = $this->forms->html('izin-kerja', ['id' => $this->project()->id]);

        $this->assertStringContainsString('ALAT YANG DIPAKAI', $html);
        $this->assertStringContainsString('POTENSI BAHAYA', $html);
        $this->assertStringContainsString('ALAT PELINDUNG DIRI', $html);
        $this->assertSame(11, substr_count($html, '<tr class="kosong">'), '5 baris alat + 6 baris bahaya');
        $this->assertStringContainsString('Petugas K3', $html);
    }

    public function test_izin_lembur_rules_twelve_worker_rows(): void
    {
        $html = $this->forms->html('izin-lembur', ['id' => $this->project()->id]);

        $this->assertStringContainsString('ALASAN LEMBUR', $html);
        $this->assertStringContainsString('TOTAL JAM', $html);
        $this->assertStringContainsString('TANDA TANGAN', $html);
        $this->assertStringContainsString('Pemohon', $html);
        $this->assertSame(12, substr_count($html, '<tr class="kosong">'));
    }

    public function test_izin_material_has_direction_boxes_and_ten_blank_rows(): void
    {
        $html = $this->forms->html('izin-material', ['id' => $this->project()->id]);

        $this->assertStringContainsString('MASUK', $html);
        $this->assertStringContainsString('KELUAR', $html);
        $this->assertStringContainsString('NO. POLISI', $html);
        $this->assertStringContainsString('SPESIFIKASI', $html);
        $this->assertStringContainsString('Security', $html);
        $this->assertSame(10, substr_count($html, '<tr class="kosong">'));
        // Unticked on both sides: the gate decides which way the load is going.
        $this->assertSame(2, substr_count($html, '<span class="kotak"></span>'));
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

    public function test_the_permit_endpoint_needs_the_permission_to_see_the_project(): void
    {
        $project = $this->project();

        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo('prj.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->refresh())
            ->get("/api/core/print/forms/izin-kerja/{$project->id}")
            ->assertForbidden();
    }
}
