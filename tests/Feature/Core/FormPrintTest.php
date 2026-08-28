<?php

namespace Tests\Feature\Core;

use InvalidArgumentException;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Formulir rumah — the forms the owner's projects have been filled in by hand
 * for years (PT Angkasa Pura / PT Jaya CM / PT Wijaya Karya letterheads).
 *
 * These are NOT the dompdf documents. dompdf lays out A4 portrait and cannot
 * lay out the weekly schedule grid at all, so the house forms are PDF-ready
 * HTML that the browser prints — which is also the only way to get landscape.
 *
 * The rule these tests exist to defend is honesty. Half the cells on the paper
 * forms have no counterpart anywhere in the ERP — manpower by role per day,
 * material ditolak, alat yang digunakan, jam kerja, perpanjangan waktu — and
 * the paper leaves them as dotted lines for the site to fill in. Printing an
 * invented number there is worse than printing nothing: the form is signed by
 * three parties and filed as the project record. So every assertion below is
 * either "this value came out of the database" or "this cell is a ruled blank".
 */
class FormPrintTest extends ErpTestCase
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

    /**
     * A project that runs the whole of 2026 — 365 days, so every day count in
     * the identity block can be read off the calendar by hand.
     */
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

    // ------------------------------------------------------- the header band

    /**
     * Four boxes across the top, in the order the paper has them. Three of the
     * four are answered by the database; the fourth is the field this lane
     * added, because nothing in the ERP stored a konsultan MK.
     */
    public function test_the_form_carries_the_four_party_band(): void
    {
        $html = $this->forms->html('data-proyek', ['id' => $this->project()->id]);

        $this->assertStringContainsString('PEMILIK', $html);
        $this->assertStringContainsString('PT Angkasa Pura I (Persero)', $html);
        $this->assertStringContainsString('KONSULTAN MK', $html);
        $this->assertStringContainsString('PT Jaya CM', $html);
        $this->assertStringContainsString('PROYEK', $html);
        $this->assertStringContainsString('PRJ-2026-001', $html);
        $this->assertStringContainsString('KONTRAKTOR', $html);
        $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
    }

    public function test_the_project_name_is_the_centred_title_the_paper_carries(): void
    {
        $html = $this->forms->html('data-proyek', ['id' => $this->project()->id]);

        $this->assertStringContainsString(
            'PENGEMBANGAN BANDAR UDARA SULTAN HASANUDIN - MAKASSAR',
            $html,
            'the house form shouts the project name across the top',
        );
    }

    /**
     * Most projects in this database have no consultant — the column did not
     * exist until now, so every historical row is null. The paper form has an
     * empty box in that case, and an empty box is what must print. "null" on a
     * document signed by three parties is the kind of thing that gets noticed.
     */
    public function test_an_absent_consultant_prints_an_empty_box_not_the_word_null(): void
    {
        $project = $this->project(['consultant_name' => null, 'consultant_role' => null]);

        $html = $this->forms->html('data-proyek', ['id' => $project->id]);

        $this->assertStringNotContainsString('null', $html);
        $this->assertStringNotContainsString('NULL', $html);
        // The box itself stays: the band is four columns wide on paper whether
        // or not this job has an MK.
        $this->assertStringContainsString('KONSULTAN MK', $html);
    }

    // --------------------------------------------------- the identity block

    /** Day one of a 365-day job: hari ke 1, minggu ke 1, 364 days left. */
    public function test_the_day_counters_start_at_one_on_the_first_day(): void
    {
        $header = $this->forms->header($this->project(), ['date' => '2026-01-01']);

        $this->assertSame(1, $header['schedule']['dayNo']);
        $this->assertSame(1, $header['schedule']['weekNo']);
        $this->assertSame(365, $header['schedule']['totalDays']);
        $this->assertSame(364, $header['schedule']['remainingDays']);
    }

    /**
     * The week boundary. Day 7 is still minggu 1 and day 8 is minggu 2 — off by
     * one here and every weekly report in the file is filed under the wrong
     * week number, which is how a laporan mingguan gets rejected.
     */
    public function test_the_week_turns_over_on_the_eighth_day(): void
    {
        $project = $this->project();

        $this->assertSame(1, $this->forms->header($project, ['date' => '2026-01-07'])['schedule']['weekNo']);
        $this->assertSame(7, $this->forms->header($project, ['date' => '2026-01-07'])['schedule']['dayNo']);
        $this->assertSame(2, $this->forms->header($project, ['date' => '2026-01-08'])['schedule']['weekNo']);
        $this->assertSame(8, $this->forms->header($project, ['date' => '2026-01-08'])['schedule']['dayNo']);
    }

    public function test_the_last_day_leaves_nothing_and_is_not_a_day_short(): void
    {
        $header = $this->forms->header($this->project(), ['date' => '2026-12-31']);

        $this->assertSame(365, $header['schedule']['dayNo'], 'the last day of a 365-day job is day 365');
        $this->assertSame(53, $header['schedule']['weekNo']);
        $this->assertSame(0, $header['schedule']['remainingDays']);
    }

    /**
     * Past the contract end date the honest answer is not "0 hari". A job in
     * overrun is exactly the job whose daily report gets read, and hiding the
     * overrun on the form is hiding it from the people signing it.
     */
    public function test_past_the_end_date_the_form_says_how_far_over(): void
    {
        $header = $this->forms->header($this->project(), ['date' => '2027-01-10']);

        $this->assertSame(375, $header['schedule']['dayNo']);
        $this->assertSame(-10, $header['schedule']['remainingDays']);
        $this->assertStringContainsString('lewat 10 hari', $header['schedule']['remainingLabel']);
    }

    /**
     * A project with no dates yet is ordinary (a project is created before the
     * SPK is signed). Every counter is then a ruled blank, not a zero — zero is
     * a claim, and this one would be false.
     */
    public function test_a_project_without_dates_leaves_every_counter_blank(): void
    {
        $project = $this->project(['start_date' => null, 'end_date' => null]);
        $header = $this->forms->header($project, ['date' => '2026-01-08']);

        $this->assertNull($header['schedule']['dayNo']);
        $this->assertNull($header['schedule']['weekNo']);
        $this->assertNull($header['schedule']['remainingDays']);
    }

    public function test_the_identity_block_carries_the_customers_own_spk_number(): void
    {
        $html = $this->forms->html('data-proyek', ['id' => $this->project()->id, 'date' => '2026-06-15']);

        $this->assertStringContainsString('SPK/AP1/2025/XII/0142', $html, 'the number the customer knows the job by');
        $this->assertStringContainsString('18 Desember 2025', $html, 'tanggal SPK');
        $this->assertStringContainsString('365 hari kalender', $html);
        $this->assertStringContainsString('15 Juni 2026', $html);
    }

    /**
     * The band and the identity block survive a tidied-up customer row.
     *
     * These four relations are loaded ONCE, in the shared house header, on
     * behalf of all 22 project-backed sheets — so this is the one gap the
     * registry's own withTrashed sweep could not reach: it constrains the
     * eager loads each ENTRY declares, and these are not one of them. Loaded
     * plainly, a soft-deleted customer emptied the PEMILIK box of the
     * four-party band and a soft-deleted contract ruled NO. SPK / KONTRAK and
     * TANGGAL SPK blank.
     *
     * The constraint has to sit on project() as well as on header(): header()
     * reaches for loadMissing, which is a no-op on a relation the loader has
     * already resolved, so the guard was written once and did nothing.
     */
    public function test_the_band_still_names_an_owner_whose_row_was_deleted(): void
    {
        $project = $this->project();
        $before = $this->forms->html('data-proyek', ['id' => $project->id, 'date' => '2026-06-15']);

        $project->customer->delete();
        $project->contract->delete();

        $after = $this->forms->html('data-proyek', ['id' => $project->id, 'date' => '2026-06-15']);

        // The job did not stop having an owner or a contract number because
        // somebody tidied a row. Byte-identical is the whole assertion.
        $this->assertSame($before, $after);
        $this->assertStringContainsString('PT Angkasa Pura I (Persero)', $after);
        $this->assertStringContainsString('SPK/AP1/2025/XII/0142', $after);
        $this->assertStringContainsString('18 Desember 2025', $after);
    }

    /** The registry documents borrow that same band, so they inherit the fix. */
    public function test_a_registry_document_inherits_the_deleted_owner_guard(): void
    {
        $project = $this->project();
        $before = $this->forms->html('data-proyek', ['id' => $project->id]);

        $project->customer->delete();

        $this->assertSame($before, $this->forms->html('data-proyek', ['id' => $project->id]));
        // And the PEKERJAAN line, which the ten contract-titled sheets take
        // from the contract the customer signed.
        $this->assertStringContainsString('Pengembangan Bandar Udara Sultan Hasanudin', $before);
    }

    /**
     * Since P0-B the two PERPANJANGAN WAKTU lines print approved addendum
     * waktu (FormPrintKopWaktuTest owns that side); this contract has none, so
     * both lines keep the ruled blank the paper has always had.
     */
    public function test_a_field_the_erp_does_not_know_prints_as_a_ruled_blank(): void
    {
        $html = $this->forms->html('data-proyek', ['id' => $this->project()->id]);

        $this->assertStringContainsString('PERPANJANGAN WAKTU I', $html);
        $this->assertStringContainsString('PERPANJANGAN WAKTU II', $html);
        // The markup, not just the class in the stylesheet: an unknown value is
        // a dotted rule to write on, never a number and never an empty cell
        // somebody could read as "none".
        $this->assertStringContainsString('<span class="fill-line"></span>', $html);
        $this->assertSame(
            2,
            substr_count($html, '<span class="fill-line"></span>'),
            'exactly the two perpanjangan lines are hand-filled on this form; everything else is known',
        );
    }

    // -------------------------------------------------------- the signatures

    /**
     * Three columns, and the middle one is the reason the consultant field had
     * to be added: "Menyetujui / menolak" is the MK's box.
     */
    public function test_the_signature_block_names_who_we_know_and_rules_a_line_for_who_we_do_not(): void
    {
        $project = $this->project([
            'project_manager_id' => $this->employee('Rina Wijaya', 'Project Manager', '3273010101850001')->id,
            'site_manager_id' => $this->employee('Budi Santoso', 'Site Manager', '3273010101850002')->id,
        ]);

        $html = $this->forms->html('data-proyek', ['id' => $project->id, 'date' => '2026-06-15']);

        $this->assertStringContainsString('Mengetahui', $html);
        $this->assertStringContainsString('Menyetujui / menolak', $html);
        $this->assertStringContainsString('Kontraktor Pelaksana', $html);
        // Ours, because hr_employees knows it.
        $this->assertStringContainsString('Budi Santoso', $html);
        // Theirs, because nothing in the ERP records who signs for the owner or
        // the MK — a name invented here would be forged.
        $this->assertStringContainsString('sig-name', $html);
        $this->assertStringContainsString('Makassar, 15 Juni 2026', $html, 'kota proyek, tanggal formulir');
    }

    // ------------------------------------------------------------ the sheet

    /**
     * The sheet is opened in a new tab from a blob URL and printed. A blob URL
     * has no base, so a relative stylesheet resolves to nothing; and a page that
     * fetched a font would print differently on a laptop with no network. Same
     * rule as the dompdf letterhead, for a different reason.
     */
    public function test_the_sheet_is_standalone_and_pulls_in_nothing_from_outside(): void
    {
        $html = $this->forms->html('data-proyek', ['id' => $this->project()->id]);

        $this->assertStringStartsWith('<!DOCTYPE html>', trim($html));
        $this->assertStringContainsString('</html>', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
    }

    /**
     * The mistake the dompdf letterhead makes and this one must not: its
     * letterhead prints once, so page 2 of a long table arrives with no column
     * headings. A grouped, two-row-deep header is unreadable that way.
     */
    public function test_grouped_table_headers_repeat_on_every_page(): void
    {
        $html = $this->forms->html('data-proyek', ['id' => $this->project()->id]);

        $this->assertStringContainsString('table-header-group', $html);
        $this->assertStringContainsString('break-inside: avoid', $html, 'a row split across a page break is unreadable');
    }

    /**
     * The weekly schedule is a wide grid — the whole reason this path is not
     * dompdf. Landscape has to be reachable from a body class, because one
     * layout serves both orientations.
     */
    public function test_landscape_is_available_to_the_forms_that_need_it(): void
    {
        $html = $this->forms->html('data-proyek', ['id' => $this->project()->id]);

        $this->assertStringContainsString('@page landscape', $html);
        $this->assertStringContainsString('body.landscape', $html);
        $this->assertStringContainsString('print-color-adjust', $html, 'borders and shaded headers must survive printing');
    }

    public function test_an_unknown_form_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jenis formulir cetak tidak dikenal: laporan-bulanan.');

        $this->forms->html('laporan-bulanan', ['id' => $this->project()->id]);
    }

    // ---------------------------------------------------------- the endpoint

    public function test_the_endpoint_serves_the_form_as_printable_html(): void
    {
        $project = $this->project();

        $response = $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/data-proyek/{$project->id}?tanggal=2026-06-15")
            ->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('15 Juni 2026', $response->getContent());
    }

    /** Printing a form is reading the record; the owning module's view applies. */
    public function test_printing_a_form_needs_the_permission_to_see_the_record(): void
    {
        $project = $this->project();

        $this->actingAs($this->userWithout('prj.view'))
            ->get("/api/core/print/forms/data-proyek/{$project->id}")
            ->assertForbidden();
    }

    public function test_an_unknown_form_is_a_404_that_says_so(): void
    {
        $project = $this->project();

        $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/laporan-bulanan/{$project->id}")
            ->assertNotFound()
            ->assertJsonFragment(['message' => 'Jenis formulir cetak tidak dikenal: laporan-bulanan.']);
    }

    public function test_a_form_for_a_record_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/api/core/print/forms/data-proyek/9999')
            ->assertNotFound();
    }

    private function userWithout(string $permission)
    {
        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->refresh();
    }
}
