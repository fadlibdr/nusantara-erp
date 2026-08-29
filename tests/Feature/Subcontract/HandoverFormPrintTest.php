<?php

namespace Tests\Feature\Subcontract;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Subcontract\Models\Handover;
use Modules\Subcontract\Models\Subcontract;
use Modules\Subcontract\Services\HandoverService;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\SubcontractFixtures;

/**
 * P3 lane PRINT — F/BST-SK, the berita acara serah terima pekerjaan
 * subkontraktor, as a REGISTRY entry in PrintableDocuments::subcontract().
 *
 * The one cell worth a paragraph is MASA PEMELIHARAAN S/D. BAST I publishes
 * `retention_release_due` by COPYING the SPK's own `defect_liability_until`,
 * and an SPK that never recorded one leaves it null — HandoverService says so
 * in as many words. So the sheet rules that cell rather than computing a
 * warranty end from a period nothing in the database records. That blank is
 * also exactly the state RetentionService already refuses to release against,
 * which means the paper and the gate agree instead of the paper quietly
 * inventing the date the gate is waiting for.
 *
 * The body table is titled after what it IS — the scope as the SPK records it —
 * and not after what a handover claims. scm_handovers carries one free-text
 * `scope_notes` and no lines of its own; printing the SPK's lines under a
 * heading like "PEKERJAAN YANG DISERAHTERIMAKAN" would turn a reference into an
 * assertion that every one of them was accepted.
 */
class HandoverFormPrintTest extends ErpTestCase
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

    /** SPK Rp 500 juta, satu baris beton, masa pemeliharaan sampai 28-02-2027. */
    private function spk(array $attributes = []): Subcontract
    {
        return $this->makeApprovedSubcontract(
            array_merge([
                'title' => 'Pekerjaan struktur beton bertulang lantai 1-3',
                'value' => 500_000_000,
                'retention_pct' => 5,
                'defect_liability_until' => '2027-02-28',
            ], $attributes),
            [[
                'wbs_code' => '2.1',
                'description' => 'Beton K-300 struktur lantai 1-3',
                'qty' => 250,
                'unit' => 'm3',
                'unit_price' => 2_000_000,
                'amount' => 500_000_000,
            ]],
        );
    }

    private function handover(Subcontract $spk, string $type = 'bast1', array $attributes = []): Handover
    {
        return app(HandoverService::class)->create(array_merge([
            'subcontract_id' => $spk->id,
            'handover_type' => $type,
            'handover_date' => '2026-09-01',
            'handed_over_by' => 'Slamet Riyadi',
            'received_by' => 'Rina Wijaya',
            'scope_notes' => 'Seluruh pekerjaan struktur lantai 1 s/d 3 sesuai SPK.',
        ], $attributes));
    }

    // ---------------------------------------------------------------- sheet

    public function test_f_bst_sk_prints_the_house_sheet_with_its_form_code_and_number(): void
    {
        $handover = $this->handover($this->spk());

        $html = $this->forms->html('bast-subkon', ['id' => $handover->id]);

        $this->assertStringContainsString('BERITA ACARA SERAH TERIMA PEKERJAAN SUBKONTRAKTOR', $html);
        $this->assertStringContainsString('Form F/BST-SK', $html);
        $this->assertStringContainsString($handover->code, $html);
        // The counterparty of a BAST subkon is the SUBCONTRACTOR, so the sheet
        // carries the vendor band and not the four-party owner band.
        $this->assertStringContainsString('PT Subkon Jaya Konstruksi', $html);
    }

    public function test_the_sheet_states_which_handover_it_is(): void
    {
        $spk = $this->spk();

        $first = $this->forms->html('bast-subkon', ['id' => $this->handover($spk)->id]);
        $second = $this->forms->html('bast-subkon', [
            'id' => $this->handover($spk, 'bast2', ['handover_date' => '2027-03-01'])->id,
        ]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('JENIS SERAH TERIMA', 'BAST I (serah terima pertama)'),
            $first,
        );
        $this->assertMatchesRegularExpression(
            $this->identityCell('JENIS SERAH TERIMA', 'BAST II (akhir masa pemeliharaan)'),
            $second,
        );
    }

    public function test_the_scope_table_prints_the_spk_lines_it_refers_to(): void
    {
        $html = $this->forms->html('bast-subkon', ['id' => $this->handover($this->spk())->id]);

        $this->assertStringContainsString('LINGKUP PEKERJAAN MENURUT SPK', $html);
        $this->assertStringContainsString('Beton K-300 struktur lantai 1-3', $html);
        // 250 m3, Rp 500.000.000 — the SPK's own figures, unchanged.
        $this->assertMatchesRegularExpression($this->numCell('250'), $html);
        $this->assertMatchesRegularExpression($this->numCell('500.000.000,00'), $html);
    }

    /**
     * BAST I copies the SPK's masa pemeliharaan; the retention clock the sheet
     * starts is the one RetentionService later measures.
     */
    public function test_bast1_prints_the_retention_due_date_it_copied_from_the_spk(): void
    {
        $html = $this->forms->html('bast-subkon', ['id' => $this->handover($this->spk())->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('RETENSI DAPAT DILEPAS MULAI', '28 Februari 2027'),
            $html,
        );
    }

    /**
     * THE HONESTY ASSERTION. An SPK with no masa pemeliharaan gives BAST I no
     * date to publish; the cell is ruled for the pen, never computed from a
     * period nothing recorded.
     */
    public function test_a_spk_without_a_maintenance_period_rules_the_retention_date(): void
    {
        $spk = $this->spk(['defect_liability_until' => null]);

        $html = $this->forms->html('bast-subkon', ['id' => $this->handover($spk)->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('RETENSI DAPAT DILEPAS MULAI'), $html);
    }

    /**
     * The approved opnames behind the handover — the prerequisite, on the
     * paper. A BAST whose SPK has none says so rather than printing a grid that
     * reads as "no work was done".
     */
    public function test_the_sheet_lists_the_approved_opname_behind_the_handover(): void
    {
        $spk = $this->spk();
        $claim = $this->approvedClaim($spk, [$spk->items()->first()->id => 100]);

        $html = $this->forms->html('bast-subkon', ['id' => $this->handover($spk)->id]);

        $this->assertStringContainsString('OPNAME YANG SUDAH DISETUJUI', $html);
        $this->assertStringContainsString($claim->code, $html);
    }

    public function test_a_handover_with_no_approved_opname_says_so(): void
    {
        $html = $this->forms->html('bast-subkon', ['id' => $this->handover($this->spk())->id]);

        $this->assertStringContainsString('belum memiliki opname yang disetujui', $html);
    }

    /**
     * The handing-over and receiving names are RECORDED columns — the two
     * people who actually stood on site — so they print. Nothing on this sheet
     * borrows a name from vendor or project master data.
     */
    public function test_the_two_recorded_representatives_are_printed(): void
    {
        $html = $this->forms->html('bast-subkon', ['id' => $this->handover($this->spk())->id]);

        $this->assertMatchesRegularExpression($this->identityCell('YANG MENYERAHKAN', 'Slamet Riyadi'), $html);
        $this->assertMatchesRegularExpression($this->identityCell('YANG MENERIMA', 'Rina Wijaya'), $html);
    }

    public function test_an_unrecorded_representative_is_a_ruled_blank(): void
    {
        $handover = $this->handover($this->spk(), 'bast1', ['handed_over_by' => null]);

        $html = $this->forms->html('bast-subkon', ['id' => $handover->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('YANG MENYERAHKAN'), $html);
    }

    // ------------------------------------------------------------- helpers

    private function identityCell(string $label, string $value): string
    {
        return '~>'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*'
            .preg_quote($value, '~').'\s*</td>~';
    }

    private function ruledIdentityCell(string $label): string
    {
        return $this->identityCell($label, '<span class="fill-line"></span>');
    }

    /** One numeric BODY cell, as the generic sheet writes it. */
    private function numCell(string $value): string
    {
        return '~<td class="num">\s*'.preg_quote($value, '~').'\s*</td>~';
    }
}
