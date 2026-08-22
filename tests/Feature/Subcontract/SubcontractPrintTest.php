<?php

namespace Tests\Feature\Subcontract;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Core\Support\Terbilang;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Models\SubcontractAddendum;
use Modules\Subcontract\Services\AddendumService;
use Modules\Subcontract\Services\AdvanceService;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * Formulir rumah untuk Subkontrak — SPK, addendum dan berita acara opname.
 *
 * Three documents whose numbers are already computed and stored by
 * SubcontractService, AddendumService and ClaimService, so almost every cell
 * here is a straight read. The interesting cells are the four that are not:
 *
 *   TERMIN PEMBAYARAN. scm_subcontracts records the retention percentage, the
 *   PPh scheme and the two dates, and no payment schedule of any kind. The
 *   vendor's payment_term_days is a MASTER-DATA default for invoices, not a
 *   term of this SPK — printing it under "termin pembayaran" would put a
 *   contract term on a signed sheet that nobody agreed to. Ruled for the pen,
 *   exactly as PERPANJANGAN WAKTU is on the house block.
 *
 *   PPN ON A NON-PKP SUBCONTRACTOR. ppn_rate 0 is not an unknown: the
 *   migration says it is 0 precisely when the vendor is not PKP. A ruled blank
 *   there would invite somebody to add PPN to a bill that must not carry it,
 *   so the sheet states the fact in words.
 *
 *   NILAI SPK SEBELUM / SESUDAH on an addendum. Before approval both are
 *   arithmetic on the live SPK value. After approval the SPK value has already
 *   moved, and original_value is what makes the pair reconstructible — but
 *   ONLY while this is the SPK's single approved addendum. Once a second one
 *   lands, the intermediate value cannot be proven from any column, and the
 *   sheet rules both rather than printing a number that looks derived.
 *
 *   A DP CLAIM has no progress lines at all. It shares scm_progress_claims
 *   with the opnames, so the sheet says which kind it is and its body table
 *   says out loud that there are no progress lines, instead of printing an
 *   opname with every percentage at zero.
 */
class SubcontractPrintTest extends ErpTestCase
{
    use SubcontractFixtures;

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

    // ------------------------------------------------------------- fixtures

    /**
     * SPK 500 juta, satu baris beton, PPN 11 %, retensi 5 %, PPh final 2,65 %.
     */
    private function spk(array $attributes = []): Subcontract
    {
        $spk = $this->makeApprovedSubcontract(array_merge([
            'title' => 'Pekerjaan struktur beton bertulang lantai 1-3',
            'scope' => 'Meliputi pembesian, bekisting, pengecoran dan perawatan beton.',
            'value' => 500_000_000,
            'defect_liability_until' => '2027-02-28',
        ], $attributes));

        $this->addLine($spk, [
            'wbs_code' => '2.1',
            'description' => 'Beton K-300 struktur lantai 1-3',
            'qty' => 250,
            'unit' => 'm3',
            'unit_price' => 2_000_000,
            'amount' => 500_000_000,
        ]);

        return $spk->refresh();
    }

    /** SPK 100 juta with one line — small enough to stay under the director gate. */
    private function amendableSpk(): Subcontract
    {
        $spk = $this->makeApprovedSubcontract(['value' => 100_000_000, 'title' => 'Pekerjaan lansekap']);

        $this->addLine($spk, [
            'description' => 'Pekerjaan lansekap halaman depan',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 100_000_000,
            'amount' => 100_000_000,
        ]);

        return $spk->refresh();
    }

    private function addendum(Subcontract $spk, float $change, array $extra = []): SubcontractAddendum
    {
        $data = array_merge([
            'subcontract_id' => $spk->id,
            'addendum_date' => '2026-06-01',
            'title' => 'Tambah pekerjaan ME lantai 2',
            'reason' => 'kondisi_lapangan',
            'value_change' => $change,
            'items' => [[
                'description' => 'Pekerjaan tambahan ME lantai 2',
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $change,
            ]],
        ], $extra);

        return app(AddendumService::class)->create($data);
    }

    private function approveAddendum(SubcontractAddendum $addendum): SubcontractAddendum
    {
        $addendum->submit($this->actor());

        return app(AddendumService::class)->approve($addendum->refresh(), $this->approver());
    }

    // ------------------------------------------------------------ spk subkon

    public function test_the_spk_sheet_prints_the_scope_the_value_and_the_tax_terms(): void
    {
        $spk = $this->spk();

        $html = $this->forms->html('spk-subkon', ['id' => $spk->id]);

        $this->assertStringContainsString('SURAT PERINTAH KERJA', $html);
        $this->assertStringContainsString('Form F/SP', $html);
        $this->assertStringContainsString($spk->code, $html);
        $this->assertStringContainsString('PT Subkon Jaya Konstruksi', $html);
        $this->assertStringContainsString('Beton K-300 struktur lantai 1-3', $html);
        $this->assertStringContainsString('500.000.000,00', $html);
        // Retensi: 5 % of the DPP, stated as both the rate and the money.
        $this->assertStringContainsString('5%', $html);
        $this->assertStringContainsString('25.000.000,00', $html);
        // PPh final konstruksi: the scheme AND its snapshotted rate.
        $this->assertStringContainsString('2,65%', $html);
        $this->assertStringContainsString('bersertifikat', $html);
        // PPN 11 % on the DPP, and the contract value with it.
        $this->assertStringContainsString('PPN 11%', $html);
        $this->assertStringContainsString('55.000.000,00', $html);
        $this->assertStringContainsString('555.000.000,00', $html);
        // Waktu pelaksanaan and the masa pemeliharaan the retention guards.
        $this->assertStringContainsString('01 Februari 2026', $html);
        $this->assertStringContainsString('31 Agustus 2026', $html);
        $this->assertStringContainsString('28 Februari 2027', $html);
        // The scope narrative belongs on the sheet the subcontractor signs.
        $this->assertStringContainsString('pembesian, bekisting, pengecoran', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * On the CELL, because the sheet is full of rules for other reasons.
     *
     * An SPK's house identity block rules PERPANJANGAN WAKTU on every copy and
     * the notes block rules two more lines, so
     * assertStringContainsString('fill-line') is true of every SPK ever
     * printed — it stayed true with TERMIN PEMBAYARAN printing the words
     * "Sesuai kesepakatan", which is a payment term on a signed work order
     * that no column records and nobody agreed to.
     */
    public function test_the_payment_schedule_is_ruled_because_no_column_records_one(): void
    {
        $html = $this->forms->html('spk-subkon', ['id' => $this->spk()->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('TERMIN PEMBAYARAN'), $html);
        // The vendor's master-data invoice term is not a term of this SPK.
        $this->assertStringNotContainsString('30 hari', $html);
        // The line above it is a stored column and does print, so this block
        // is not simply blank.
        $this->assertMatchesRegularExpression($this->identityCell('TARIF PPh FINAL', '2,65%'), $html);
    }

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

    public function test_a_non_pkp_spk_states_that_no_ppn_is_charged(): void
    {
        $spk = $this->spk(['ppn_rate' => 0]);

        $html = $this->forms->html('spk-subkon', ['id' => $spk->id]);

        $this->assertStringContainsString('Tidak dikenakan PPN', $html);
    }

    public function test_a_qualification_override_is_printed_on_the_spk_that_carries_one(): void
    {
        $spk = $this->spk([
            'qualification_override_reason' => 'SBU habis 2 minggu lalu, perpanjangan sedang diproses.',
        ]);

        $html = $this->forms->html('spk-subkon', ['id' => $spk->id]);

        $this->assertStringContainsString('Override prakualifikasi', $html);
        $this->assertStringContainsString('SBU habis 2 minggu lalu', $html);
    }

    // --------------------------------------------------------- addendum spk

    public function test_an_unapproved_addendum_states_the_value_it_would_move_the_spk_to(): void
    {
        $spk = $this->amendableSpk();
        $addendum = $this->addendum($spk, 50_000_000);

        $html = $this->forms->html('addendum-spk', ['id' => $addendum->id]);

        $this->assertStringContainsString('ADDENDUM', $html);
        $this->assertStringContainsString('Form F/AS', $html);
        $this->assertStringContainsString($spk->code, $html);
        $this->assertStringContainsString('Tambah-Kurang', $html);
        $this->assertStringContainsString('Kondisi lapangan', $html);
        $this->assertStringContainsString('Pekerjaan tambahan ME lantai 2', $html);
        $this->assertStringContainsString('50.000.000,00', $html);
        $this->assertStringContainsString('100.000.000,00', $html); // sebelum
        $this->assertStringContainsString('150.000.000,00', $html); // sesudah
    }

    public function test_an_approved_addendum_reads_its_before_value_off_original_value(): void
    {
        $spk = $this->amendableSpk();
        $addendum = $this->approveAddendum($this->addendum($spk, 50_000_000));

        $this->assertEqualsWithDelta(150_000_000, (float) $spk->refresh()->value, 0.01);

        $html = $this->forms->html('addendum-spk', ['id' => $addendum->id]);

        $this->assertStringContainsString('100.000.000,00', $html); // sebelum, dari original_value
        $this->assertStringContainsString('150.000.000,00', $html); // sesudah, nilai SPK berjalan
    }

    /**
     * Two approved addenda and the intermediate value is gone: original_value
     * is the value before the FIRST one and scm_subcontracts.value is the
     * value after the LAST, with nothing in between recorded anywhere. A
     * before/after pair printed here would be arithmetic dressed as a fact, so
     * both cells are ruled.
     */
    public function test_an_addendum_superseded_by_a_later_one_rules_its_before_and_after(): void
    {
        $spk = $this->amendableSpk();
        $first = $this->approveAddendum($this->addendum($spk, 50_000_000));
        $this->approveAddendum($this->addendum($spk->refresh(), 25_000_000, [
            'addendum_date' => '2026-07-01',
            'title' => 'Tambah pekerjaan plafon',
        ]));

        $html = $this->forms->html('addendum-spk', ['id' => $first->id]);

        $this->assertStringNotContainsString('100.000.000,00', $html);
        $this->assertStringNotContainsString('175.000.000,00', $html);
        // Its own change is still a fact and still prints.
        $this->assertStringContainsString('50.000.000,00', $html);
    }

    // -------------------------------------------------------- opname subkon

    public function test_the_opname_sheet_prints_the_progress_columns_and_the_payment_arithmetic(): void
    {
        $spk = $this->spk();
        $line = $spk->items()->first();
        $claim = $this->draftClaim($spk, [$line->id => 20]);

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        $this->assertStringContainsString('BERITA ACARA OPNAME', $html);
        $this->assertStringContainsString('Form F/BO', $html);
        $this->assertStringContainsString($claim->code, $html);
        $this->assertStringContainsString($spk->code, $html);
        $this->assertStringContainsString('Beton K-300 struktur lantai 1-3', $html);
        // Volume kontrak, s/d lalu, periode ini, kumulatif.
        $this->assertStringContainsString('250', $html);
        $this->assertStringContainsString('20%', $html);
        // gross 100 juta, retensi 5 juta, netto sebelum pajak 95 juta,
        // PPN 11 juta, PPh final 2,65 juta, dibayar 103,35 juta.
        $this->assertStringContainsString('100.000.000,00', $html);
        $this->assertStringContainsString('5.000.000,00', $html);
        $this->assertStringContainsString('95.000.000,00', $html);
        $this->assertStringContainsString('11.000.000,00', $html);
        $this->assertStringContainsString('2.650.000,00', $html);
        $this->assertStringContainsString('103.350.000,00', $html);
        $this->assertStringContainsString(Terbilang::rupiah(103_350_000), $html);
    }

    /**
     * The DP claim rides in the same table as the opnames and must not be
     * printed as one: it has no progress lines, and a body of zero-percent
     * rows would read as an opname where nothing was built.
     */
    public function test_an_advance_claim_names_its_kind_and_prints_no_progress_lines(): void
    {
        $spk = $this->spk();
        $claim = app(AdvanceService::class)->createClaim($spk, [
            'amount' => 100_000_000,
            'claim_date' => '2026-02-05',
        ]);

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        $this->assertStringContainsString('Uang muka', $html);
        $this->assertStringContainsString('tidak memiliki baris progres', $html);
        // DP: no retention, no PPh, PPN on the full amount.
        $this->assertStringContainsString('111.000.000,00', $html);
        $this->assertStringNotContainsString('Beton K-300', $html);
    }

    /**
     * On a DP the CAPTIONS move with the kind of claim.
     *
     * scm_progress_claims has one period_start and one period_end and
     * AdvanceService writes the claim date into both, so under the opname's own
     * headings a DP printed "PERIODE DARI : 05 Februari 2026 / PERIODE S/D : 05
     * Februari 2026" — one stored date twice, under two captions that both
     * promise a period of WORK, directly above a body that says in a sentence
     * that there are no progress lines. The date is claimed once as what it is,
     * the second line says in words that there is no work period rather than
     * ruling a blank somebody could write one into, and the totals row asks for
     * an advance instead of for work done.
     */
    public function test_an_advance_claim_captions_the_date_and_the_total_for_what_they_are(): void
    {
        $spk = $this->spk();
        $claim = app(AdvanceService::class)->createClaim($spk, [
            'amount' => 100_000_000,
            'claim_date' => '2026-02-05',
        ]);

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        // On the CELLS: the stored date claimed ONCE as what it is, and the
        // second line saying in words that there is no work period.
        $this->assertMatchesRegularExpression($this->identityCell('TANGGAL KLAIM', '05 Februari 2026'), $html);
        $this->assertMatchesRegularExpression($this->identityCell('PERIODE PEKERJAAN', 'Tidak ada'), $html);
        $this->assertStringContainsString('Jumlah uang muka (DPP)', $html);
        // The opname's own wording must not survive onto a DP.
        $this->assertStringNotContainsString('Jumlah pekerjaan periode ini', $html);
        $this->assertStringNotContainsString('PERIODE DARI', $html);
        $this->assertStringNotContainsString('PERIODE S/D', $html);
    }

    /**
     * And an ordinary opname keeps every caption it always had — AND prints a
     * date under them.
     *
     * The VALUE is asserted, not only the caption, because PERIODE S/D is the
     * one identity line in this registry that deliberately drops its 'date'
     * cast: it has to answer with a Carbon on an opname and with the sentence
     * "Tidak ada" on a DP, and a date cast would hand that sentence to
     * Carbon::parse. What holds the line together is FormPrintService::text()
     * formatting a DateTimeInterface exactly as the cast would — nothing else
     * in the suite says so, and the day it stops the sheet prints an ISO
     * timestamp under a caption its sibling line spells "01 Maret 2026".
     */
    public function test_an_ordinary_opname_keeps_the_period_captions(): void
    {
        $spk = $this->spk();
        $claim = $this->draftClaim($spk, [$spk->items()->first()->id => 20]);

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        // The cast line and the uncast one, side by side, spelt identically.
        $this->assertMatchesRegularExpression($this->identityCell('PERIODE DARI', '01 Maret 2026'), $html);
        $this->assertMatchesRegularExpression($this->identityCell('PERIODE S/D', '31 Maret 2026'), $html);
        $this->assertStringContainsString('Jumlah pekerjaan periode ini', $html);
        $this->assertStringNotContainsString('TANGGAL KLAIM', $html);
        $this->assertStringNotContainsString('Jumlah uang muka (DPP)', $html);
    }

    // --------------------------------------- subkontraktor yang diarsipkan

    /**
     * A subcontractor archived since the SPK was signed keeps his name on the
     * work order HE SIGNED.
     *
     * prc_vendors soft-deletes on the ordinary path — a subcontractor stops
     * trading, or is struck off the approved list — while the SPK stays in the
     * project file for ever. The band of this sheet IS the subcontractor, and
     * the third signature column is his: loaded plainly, an SPK for
     * Rp 555.000.000,00 printed with an empty PENYEDIA JASA box over a
     * signature rule with nobody's name above it.
     */
    public function test_an_archived_subcontractor_keeps_his_name_on_the_spk_he_signed(): void
    {
        $spk = $this->spk();

        $spk->vendor->delete();

        $html = $this->forms->html('spk-subkon', ['id' => $spk->id]);

        $this->assertStringContainsString('PT Subkon Jaya Konstruksi', $html);
        $this->assertStringContainsString('555.000.000,00', $html);
    }

    /**
     * And on the berita acara that pays him, which is the sheet where the name
     * sits beside the money rather than merely above it.
     */
    public function test_an_archived_subcontractor_keeps_his_name_on_the_opname_that_pays_him(): void
    {
        $spk = $this->spk();
        $claim = $this->draftClaim($spk, [$spk->items()->first()->id => 20]);

        $spk->vendor->delete();

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('SUBKONTRAKTOR', 'PT Subkon Jaya Konstruksi'),
            $html,
        );
        $this->assertStringContainsString('103.350.000,00', $html);
    }

    // ---------------------------------------------------------- the endpoint

    public function test_every_subcontract_document_is_catalogued_for_its_resource(): void
    {
        $catalogue = collect(
            $this->actingAs($this->adminUser())
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('subcontract/subcontracts', $catalogue['spk-subkon']['resource']);
        $this->assertSame('subcontract/addenda', $catalogue['addendum-spk']['resource']);
        $this->assertSame('subcontract/progress-claims', $catalogue['opname-subkon']['resource']);
    }

    public function test_the_endpoint_serves_the_spk_sheet_as_printable_html(): void
    {
        $spk = $this->spk();

        $response = $this->actingAs($this->adminUser())
            ->get("/api/core/print/forms/spk-subkon/{$spk->id}")
            ->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('SURAT PERINTAH KERJA', $response->getContent());
    }

    // -------------------------------------------- apa yang TIDAK dipotong

    /**
     * A DP sheet must not state rates that were deliberately not applied.
     *
     * ClaimService::recalcTotals says it plainly — "The DP claim itself
     * withholds nothing" — so retensi and PPh are both 0,00 on an uang muka.
     * Printed as "Retensi ditahan (5%) … 0,00" the arithmetic is checkable and
     * wrong: 5 % of the gross is not nothing, and a correct sheet ends up
     * looking like a mistake.
     */
    public function test_an_advance_sheet_does_not_quote_rates_it_did_not_apply(): void
    {
        $spk = $this->spk();
        $claim = app(AdvanceService::class)->createClaim($spk, [
            'amount' => 100_000_000,
            'claim_date' => '2026-02-05',
        ]);

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        $this->assertStringContainsString('tidak ditahan atas uang muka', $html);
        $this->assertStringContainsString('dipotong pada opname berikutnya', $html);
    }

    /** The ordinary opname still quotes the rates it really applied. */
    public function test_an_ordinary_opname_still_quotes_its_retention_and_pph_rates(): void
    {
        $spk = $this->spk();
        $claim = $this->draftClaim($spk, [$spk->items()->first()->id => 20]);

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        $this->assertStringContainsString('Retensi ditahan (5%)', $html);
        $this->assertStringContainsString('PPh final konstruksi 2,65% (dipotong)', $html);
        $this->assertStringNotContainsString('tidak ditahan atas uang muka', $html);
    }

    /**
     * The value is labelled BERJALAN because it is the live one.
     *
     * scm_progress_claims stores no contract-value snapshot and an addendum
     * moves scm_subcontracts.value, so a berita acara reprinted afterwards
     * cannot state the figure the three signatures were put under. Saying
     * which figure it IS beats implying it is the other one.
     */
    public function test_the_opname_names_the_contract_value_as_the_running_one(): void
    {
        $spk = $this->spk();
        $claim = $this->draftClaim($spk, [$spk->items()->first()->id => 20]);

        $html = $this->forms->html('opname-subkon', ['id' => $claim->id]);

        $this->assertStringContainsString('NILAI SPK BERJALAN (DPP)', $html);
        // Nine body columns do not fit a portrait text block. Asserted on the
        // BODY CLASS, which is the only place the orientation is decided: the
        // word "landscape" is in the @page rule and three body.landscape rules
        // of layout.blade.php, so it is on every sheet this ERP prints,
        // portrait ones included.
        $this->assertStringContainsString('<body class="landscape">', $html);
    }
}
