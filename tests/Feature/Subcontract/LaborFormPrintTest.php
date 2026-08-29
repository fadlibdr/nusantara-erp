<?php

namespace Tests\Feature\Subcontract;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Tests\ErpTestCase;
use Tests\Unit\Subcontract\LaborFixtures;

/**
 * P4 PRINT — F/CVM (CV mandor) dan F/RU (rekap upah per proyek per periode),
 * keduanya entri registri PrintableDocuments.
 *
 * Aturan kejujuran yang dipaku di sini: F/CVM hanya mencetak riwayat SP3 yang
 * approved/closed (pengalaman yang sistem ini saksikan sendiri) dan berkata
 * apa adanya bila belum ada; F/RU mencetak status per baris dan SENGAJA tanpa
 * baris total — rekap yang menjumlah draf dan approved dalam satu angka
 * adalah klaim yang belum ditandatangani siapa pun.
 */
class LaborFormPrintTest extends ErpTestCase
{
    use LaborFixtures;

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

    public function test_cv_mandor_mencetak_identitas_riwayat_sp3_dan_register_dokumen(): void
    {
        $mandor = $this->makeMandor(['name' => 'Mandor Pak Harjo', 'npwp' => '09.876.543.2-101.000']);
        $mandor->documents()->create([
            'doc_type' => 'cv_mandor', 'name' => 'CV Mandor Pak Harjo', 'number' => 'CV-2026-01',
        ]);

        // Satu SP3 approved (masuk riwayat) + satu draf (TIDAK masuk).
        $this->makeApprovedLaborContract(
            ['vendor_id' => $mandor->id, 'title' => 'Upah pasangan bata tower A'],
            [['qty' => 100, 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $this->makeLaborContract(
            ['vendor_id' => $mandor->id, 'title' => 'Upah plesteran tower B (draf)'],
            [['qty' => 50, 'unit_rate' => 40000, 'amount' => 2000000]],
        );

        $html = $this->forms->html('cv-mandor', ['id' => $mandor->id]);

        $this->assertStringContainsString('DAFTAR RIWAYAT HIDUP (CV) MANDOR', $html);
        $this->assertStringContainsString('Form F/CVM', $html);
        $this->assertStringContainsString('Mandor Pak Harjo', $html);
        $this->assertStringContainsString('Mandor', $html); // JENIS VENDOR dari enum label
        $this->assertStringContainsString('Upah pasangan bata tower A', $html);
        $this->assertStringNotContainsString('Upah plesteran tower B (draf)', $html);
        $this->assertStringContainsString('CV Mandor', $html); // label enum register dokumen
        $this->assertStringContainsString('CV-2026-01', $html);
    }

    public function test_cv_mandor_tanpa_sp3_berkata_apa_adanya(): void
    {
        $mandor = $this->makeMandor(['name' => 'Mandor Baru']);

        $html = $this->forms->html('cv-mandor', ['id' => $mandor->id]);

        $this->assertStringContainsString('Belum ada SP3 tercatat untuk mandor ini.', $html);
    }

    public function test_rekap_upah_memuat_opname_seproyek_seperiode_dengan_status_tanpa_total(): void
    {
        $projectId = $this->defaultLaborProject()->id;

        // Mandor A: opname approved Maret (jangkar).
        $contractA = $this->makeApprovedLaborContract(
            ['title' => 'Upah bata'],
            [['qty' => 100, 'unit_rate' => 50000, 'amount' => 5000000]],
        );
        $anchor = $this->approvedLaborClaim($contractA, [$contractA->items()->first()->id => 60]);

        // Mandor B, proyek sama: opname DRAF Maret — ikut tercetak, berstatus.
        $mandorB = $this->makeMandor(['name' => 'Mandor Ibu Siti']);
        $contractB = $this->makeApprovedLaborContract(
            ['vendor_id' => $mandorB->id, 'title' => 'Upah plesteran'],
            [['qty' => 80, 'unit_rate' => 40000, 'amount' => 3200000]],
        );
        $draftB = $this->draftLaborClaim($contractB, [$contractB->items()->first()->id => 20]);

        // Mandor A, periode APRIL — di luar irisan, tidak tercetak.
        $april = $this->draftLaborClaim($contractA, [$contractA->items()->first()->id => 10], [
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);

        $html = $this->forms->html('rekap-upah', ['id' => $anchor->id]);

        $this->assertStringContainsString('REKAP UPAH MANDOR PER PROYEK PER PERIODE', $html);
        $this->assertStringContainsString('Form F/RU', $html);
        $this->assertStringContainsString($anchor->code, $html);
        $this->assertStringContainsString($draftB->code, $html);
        $this->assertStringContainsString('Mandor Ibu Siti', $html);
        $this->assertStringNotContainsString($april->code, $html);

        // Status tercetak per baris; dua status yang berbeda dua-duanya tampak.
        $this->assertStringContainsString('Disetujui', $html);
        $this->assertStringContainsString('Draf', $html);
    }
}
