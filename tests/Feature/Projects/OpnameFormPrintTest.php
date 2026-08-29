<?php

namespace Tests\Feature\Projects;

use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Company;
use Modules\Core\Models\Location;
use Modules\Core\Services\FormPrintService;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Enums\CertifyingParty;
use Modules\Projects\Enums\ZoneCertificateStatus;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\ZoneCertificate;
use Modules\Projects\Services\MeasurementService;
use Modules\Projects\Services\ZoneCertificateService;
use Modules\Quality\Services\NcrService;
use Tests\ErpTestCase;

/**
 * P3 lane PRINT — F/OPN (backsheet opname) and F/BAPP (berita acara pemeriksaan
 * per zona), as REGISTRY entries in PrintableDocuments::projects(), never
 * bespoke composers.
 *
 * These two sheets are where the honesty rule earns its keep, because both are
 * about to be worth money:
 *
 *   F/OPN is the backsheet of an owner claim. An item the opname never measured
 *   has NO ROW on it. Padding the sheet with every BOQ line at 0,000 would put
 *   a measured zero on a sheet the MK signs — a claim that the excavation was
 *   inspected and found not to have moved, which is a different statement from
 *   "not measured this period" and the one that gets quoted back in a dispute.
 *   Beside the cumulative volume the sheet prints the CEILING (contract volume
 *   plus approved CCO volume), which is the number the signature is checked
 *   against; the ceiling is a fact about the CONTRACT and is ruled blank, never
 *   guessed, when the BOQ item behind the line has gone.
 *
 *   F/BAPP is what the owner claim reads to refuse a zone (kriteria #6). A zone
 *   marked "Nunggu perbaikan" prints THAT WORD. A blank there would read as an
 *   unremarkable zone on a signed sheet, and the sheet is exactly the evidence
 *   the refusal rests on. Its signing party is nullable BY DESIGN (roadmap §7):
 *   a BAPP the MK has not yet walked rules those cells rather than borrowing a
 *   name from project master data.
 */
class OpnameFormPrintTest extends ErpTestCase
{
    use OpnameFixtures;

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forms = app(FormPrintService::class);
        $this->seedOpnameWorld();

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

    // ------------------------------------------------------------- fixtures

    /**
     * One opname measuring galian only: 400 m3 of the contract's 1.000 m3.
     * Beton K-350 is deliberately NOT measured, which is what the honesty
     * assertion below is about.
     */
    private function measurement(array $lines = [], array $attributes = []): ProgressMeasurement
    {
        $measurement = app(MeasurementService::class)->create(array_merge([
            'project_id' => $this->project->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'items' => $lines === [] ? [$this->line('A.1', 400)] : $lines,
        ], $attributes));

        return $measurement->fresh(['items', 'project', 'contract']);
    }

    private function certificate(array $attributes = []): ZoneCertificate
    {
        return app(ZoneCertificateService::class)->create(array_merge([
            'project_id' => $this->project->id,
            'location_id' => $this->makeZone('ZN-A', 'Zona A')->id,
            'status' => ZoneCertificateStatus::Check->value,
        ], $attributes));
    }

    // ----------------------------------------------------------------- F/OPN

    public function test_f_opn_prints_the_house_sheet_with_its_form_code_and_number(): void
    {
        $measurement = $this->measurement();

        $html = $this->forms->html('opname-owner', ['id' => $measurement->id]);

        $this->assertStringContainsString('BERITA ACARA OPNAME PEKERJAAN', $html);
        $this->assertStringContainsString('Form F/OPN', $html);
        $this->assertStringContainsString($measurement->code, $html);
        // The four-party band, because an opname is signed by the MK.
        $this->assertStringContainsString('KONSULTAN MK', $html);
        $this->assertStringContainsString('PT Nusantara Karya Integrasi', $html);
    }

    public function test_f_opn_backsheet_prints_prev_this_and_cumulative_volume_per_item(): void
    {
        $html = $this->forms->html('opname-owner', ['id' => $this->measurement()->id]);

        $this->assertStringContainsString('Galian tanah biasa', $html);

        // The one measured row, whole and in order: nothing before, 400 m3 this
        // period, 400 cumulative against a 1.000 m3 ceiling, at the SNAPSHOTTED
        // Rp 200.000 — 400 x 200.000 = Rp 80.000.000. Asserted as one row
        // rather than six substrings, because six substrings are all true of a
        // sheet whose columns have swapped places.
        $this->assertMatchesRegularExpression(
            $this->numRow(['0', '400', '400', '1.000', '200.000,00', '80.000.000,00']),
            $html,
        );
    }

    /**
     * THE HONESTY ASSERTION. Beton K-350 is on the contract BOQ and was not
     * measured; it must not appear on the sheet at all, and certainly not as a
     * row of zeros the MK is invited to sign against.
     */
    public function test_f_opn_never_prints_a_boq_item_the_opname_did_not_measure(): void
    {
        $html = $this->forms->html('opname-owner', ['id' => $this->measurement()->id]);

        $this->assertStringNotContainsString('Beton K-350 struktur', $html);
    }

    /**
     * The ceiling beside the cumulative volume: contract qty + approved CCO
     * qty. Without it the sheet shows a number with nothing to check it
     * against, which is the whole argument for a backsheet.
     */
    public function test_f_opn_prints_the_contract_ceiling_beside_the_cumulative_volume(): void
    {
        $html = $this->forms->html('opname-owner', ['id' => $this->measurement()->id]);

        $this->assertStringContainsString('VOL. KONTRAK + CCO', $html);
        $this->assertMatchesRegularExpression($this->numCell('1.000'), $html);
    }

    /** An APPROVED change order raises the printed ceiling, an unapproved one does not. */
    public function test_the_printed_ceiling_counts_only_approved_change_orders(): void
    {
        $this->makeVariation('A.1', 200, DocumentStatus::Approved);
        $this->makeVariation('A.1', 500, DocumentStatus::Submitted);

        $html = $this->forms->html('opname-owner', ['id' => $this->measurement()->id]);

        // 1.000 + 200 approved = 1.200. The submitted 500 is not on the paper
        // because nobody has signed it.
        $this->assertMatchesRegularExpression($this->numRow(['400', '400', '1.200']), $html);
        $this->assertDoesNotMatchRegularExpression($this->numCell('1.700'), $html);
    }

    public function test_f_opn_prints_the_period_and_cumulative_totals_it_stored(): void
    {
        $html = $this->forms->html('opname-owner', ['id' => $this->measurement()->id]);

        $this->assertStringContainsString('JUMLAH NILAI PERIODE INI', $html);
        $this->assertStringContainsString('NILAI KUMULATIF TERUKUR', $html);
        $this->assertStringContainsString('80.000.000,00', $html);
    }

    /**
     * An opname with no lines states so in words rather than printing an empty
     * grid that reads as "nothing was built".
     */
    public function test_an_opname_with_no_lines_says_so_instead_of_ruling_a_grid(): void
    {
        $measurement = $this->measurement([$this->line('A.1', 400)]);
        $measurement->items()->delete();

        $html = $this->forms->html('opname-owner', ['id' => $measurement->id]);

        $this->assertStringContainsString('belum memiliki baris volume terukur', $html);
    }

    /**
     * Roadmap §7: nothing writes an owner/MK name onto this sheet. The house
     * signature block names OUR column only, and the MK's is a rule.
     */
    public function test_f_opn_names_no_signatory_for_the_owner_or_the_mk(): void
    {
        $html = $this->forms->html('opname-owner', ['id' => $this->measurement()->id]);

        $this->assertStringContainsString('Mengetahui,', $html);
        $this->assertStringContainsString('Kontraktor Pelaksana', $html);
    }

    // ---------------------------------------------------------------- F/BAPP

    public function test_f_bapp_prints_the_zone_path_and_its_form_code(): void
    {
        $html = $this->forms->html('bapp-zona', ['id' => $this->certificate()->id]);

        $this->assertStringContainsString('BERITA ACARA PEMERIKSAAN PEKERJAAN', $html);
        $this->assertStringContainsString('Form F/BAPP', $html);
        $this->assertStringContainsString('Zona A', $html);
        $this->assertStringContainsString('BAPP/', $html);
    }

    /**
     * THE WORD, not a blank. "Nunggu perbaikan" is the mark an owner claim
     * refuses to bill against (kriteria #6); printing nothing there would make
     * the signed sheet contradict the refusal it is the evidence for.
     */
    public function test_a_zone_waiting_for_repair_prints_that_word(): void
    {
        $certificate = $this->certificate(['status' => ZoneCertificateStatus::WaitingRepair->value]);

        $html = $this->forms->html('bapp-zona', ['id' => $certificate->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('HASIL PEMERIKSAAN', 'Nunggu perbaikan'),
            $html,
        );
    }

    /**
     * Roadmap §7 on the sheet itself: an unsigned BAPP rules its two signature
     * cells rather than borrowing the consultant's name from the project.
     */
    public function test_an_unsigned_bapp_rules_the_certifying_party_and_name(): void
    {
        $html = $this->forms->html('bapp-zona', ['id' => $this->certificate()->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('DIPERIKSA OLEH (PIHAK)'), $html);
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('NAMA PEMERIKSA'), $html);
        $this->assertStringNotContainsString('PT Jaya CM', $html);
    }

    /** And a recorded one prints, so the rule above is not "always blank". */
    public function test_a_recorded_certifier_is_printed_on_those_lines(): void
    {
        $certificate = $this->certificate([
            'certified_by_party' => CertifyingParty::Mk->value,
            'certified_by_name' => 'Ir. Hendra Wijaya',
            'certified_at' => '2026-07-14',
        ]);

        $html = $this->forms->html('bapp-zona', ['id' => $certificate->id]);

        $this->assertMatchesRegularExpression($this->identityCell('DIPERIKSA OLEH (PIHAK)', 'Konsultan MK'), $html);
        $this->assertMatchesRegularExpression($this->identityCell('NAMA PEMERIKSA', 'Ir. Hendra Wijaya'), $html);
    }

    /**
     * The open NCR at the zone, listed on the sheet — the same fact the `done`
     * gate refuses on, so the inspector holding the paper can see WHY the zone
     * cannot be marked finished.
     */
    public function test_f_bapp_lists_the_open_ncr_at_that_zone(): void
    {
        $zone = $this->makeZone('ZN-B', 'Zona B');

        $employee = Employee::query()->create([
            'code' => 'EMP-9001',
            'name' => 'Agus Prasetyo',
            'nik_ktp' => '3201010101010001',
            'gender' => 'male',
            'birth_date' => '1988-05-01',
            'ptkp_status' => 'K/1',
            'join_date' => '2020-01-01',
            'employment_type' => 'tetap',
            'position' => 'Site Manager',
            'department' => 'proyek',
        ]);

        $ncr = app(NcrService::class)->create([
            'project_id' => $this->project->id,
            'location_id' => $zone->id,
            'stage' => 'during',
            'description' => 'Keropos pada kolom K3 lantai 2',
            'responsible_employee_id' => $employee->id,
        ]);

        $certificate = app(ZoneCertificateService::class)->create([
            'project_id' => $this->project->id,
            'location_id' => $zone->id,
            'status' => ZoneCertificateStatus::WaitingRepair->value,
        ]);

        $html = $this->forms->html('bapp-zona', ['id' => $certificate->id]);

        $this->assertStringContainsString('NCR TERBUKA DI ZONA INI', $html);
        $this->assertStringContainsString($ncr->code, $html);
    }

    /** A clean zone says so, rather than printing an empty grid. */
    public function test_a_zone_with_no_open_ncr_says_so(): void
    {
        $html = $this->forms->html('bapp-zona', ['id' => $this->certificate()->id]);

        $this->assertStringContainsString('Tidak ada NCR terbuka di zona ini.', $html);
    }

    /**
     * AND THE POSITIVE CLAIM HAS TO BE TRUE. "Tidak ada NCR terbuka di zona
     * ini." is printed over the signatures, and a zone is a SUBTREE — the NCR
     * an inspector raised at the room inside it is an NCR in this zone. A sheet
     * that lists the codes at the zone node only would print the sentence with
     * the defect standing one level down, which is the sheet that gets quoted
     * back when the owner claim built on it is disputed.
     */
    public function test_f_bapp_lists_an_open_ncr_raised_below_the_zone(): void
    {
        $zone = $this->makeZone('ZN-C', 'Zona C');

        $room = Location::query()->create([
            'project_id' => $this->project->id,
            'parent_id' => $zone->id,
            'kind' => 'room',
            'code' => 'ZN-C-01',
            'name' => 'Ruang pompa',
        ]);

        $employee = Employee::query()->create([
            'code' => 'EMP-9002',
            'name' => 'Budi Santoso',
            'nik_ktp' => '3201010101010002',
            'gender' => 'male',
            'birth_date' => '1990-03-11',
            'ptkp_status' => 'K/0',
            'join_date' => '2021-01-01',
            'employment_type' => 'tetap',
            'position' => 'Pelaksana',
            'department' => 'proyek',
        ]);

        $ncr = app(NcrService::class)->create([
            'project_id' => $this->project->id,
            'location_id' => $room->id,
            'stage' => 'during',
            'description' => 'Pipa sprinkler bocor pada ruang pompa',
            'responsible_employee_id' => $employee->id,
        ]);

        $certificate = app(ZoneCertificateService::class)->create([
            'project_id' => $this->project->id,
            'location_id' => $zone->id,
            'status' => ZoneCertificateStatus::WaitingRepair->value,
        ]);

        $html = $this->forms->html('bapp-zona', ['id' => $certificate->id]);

        $this->assertStringContainsString($ncr->code, $html);
        $this->assertStringNotContainsString('Tidak ada NCR terbuka di zona ini.', $html);
    }

    // ------------------------------------------------------------- helpers

    /**
     * One identity ROW — the caption and the value in the same row of the
     * block. Either half on its own appears on every copy of the sheet.
     */
    private function identityCell(string $label, string $value): string
    {
        return '~>'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*'
            .preg_quote($value, '~').'\s*</td>~';
    }

    /** The same row with a RULED BLANK where the value would be. */
    private function ruledIdentityCell(string $label): string
    {
        return $this->identityCell($label, '<span class="fill-line"></span>');
    }

    /** One numeric BODY cell, as the generic sheet writes it. */
    private function numCell(string $value): string
    {
        return '~<td class="num">\s*'.preg_quote($value, '~').'\s*</td>~';
    }

    /**
     * Consecutive numeric cells of ONE row, in order.
     *
     * The columns are the point of a backsheet: qty_prev, qty_this, qty_cum and
     * the ceiling only mean anything in that sequence, and four separate
     * substring assertions stay green on a sheet whose columns have swapped.
     */
    private function numRow(array $values): string
    {
        $cells = array_map(
            static fn (string $value): string => '<td class="num">\s*'.preg_quote($value, '~').'\s*</td>',
            $values,
        );

        return '~'.implode('\s*', $cells).'~';
    }
}
