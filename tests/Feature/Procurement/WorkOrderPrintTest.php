<?php

namespace Tests\Feature\Procurement;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\WorkOrder;
use Modules\Procurement\Services\WorkOrderService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P5 (lane cetak) — Form F/PPK: lembar perintah kerja alat sewa & jasa
 * berbasis periode, formulir rumah yang ditandatangani vendor.
 *
 * Satu keputusan kejujuran yang membedakannya dari F/PO dan F/SP: setiap
 * angka di tabelnya adalah PLAFON, bukan realisasi — qty_periods memagari
 * berapa jam/hari/bulan yang BOLEH ditagih, dan realisasinya lahir per
 * periode dari register hour-meter/kalender (WorkOrderBillingService), lalu
 * ber-maker-checker di tagihan AP. Lembar ini menyebut plafon sebagai plafon
 * (JUMLAH PLAFON, TOTAL PLAFON) dan tidak mencetak satu pun angka realisasi:
 * riwayat tagihan periode adalah layar/laporannya sendiri (Rekap Tagihan
 * Alat), bukan baris di atas kertas yang tiga pihak tanda tangani sebelum
 * satu jam pun tercatat.
 *
 * Totals dari kolom TERSIMPAN: value = Σ amount baris (WorkOrderService::
 * recalcValue), ppn_rate = snapshot saat dibuat. PPN dihitung dari dua kolom
 * dokumen itu sendiri (pola SubcontractFormService::ppnAmount) — bukan dari
 * config hari ini.
 */
class WorkOrderPrintTest extends ErpTestCase
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

    // ------------------------------------------------------------- fixtures

    private function rentalVendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'name' => 'PT Alat Berat Nusantara',
            'classification' => 'jasa',
            'vendor_type' => 'rental',
            'is_pkp' => true,
            'npwp' => '03.456.789.0-034.000',
            'address' => 'Jl. Logistik Raya No. 7, Cakung',
            'city' => 'Jakarta Timur',
            'status' => 'active',
        ], $attributes));
    }

    private function project(): Project
    {
        return Project::query()->firstOrCreate(['code' => 'PRJ-2026-001'], [
            'name' => 'Pengembangan Bandar Udara Sultan Hasanudin - Makassar',
            'type' => 'construction',
            'status' => 'active',
            'city' => 'Makassar',
            'province' => 'Sulawesi Selatan',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function workOrder(array $overrides = []): WorkOrder
    {
        return app(WorkOrderService::class)->create(array_merge([
            'vendor_id' => $this->rentalVendor()->id,
            'project_id' => $this->project()->id,
            'title' => 'Sewa alat berat tahap struktur apron timur',
            'start_date' => '2026-06-01',
            'end_date' => '2026-12-31',
            'items' => [
                [
                    'description' => 'Sewa scaffolding lengkap dengan pemasangan',
                    'rate_basis' => 'per_bulan',
                    'rate' => 15_000_000,
                    'qty_periods' => 6,
                ],
                [
                    'description' => 'Operator tambahan shift malam',
                    'rate_basis' => 'per_hari_8jam',
                    'rate' => 500_000,
                    'qty_periods' => 20,
                ],
            ],
        ], $overrides));
    }

    /** An identity row rendered with the value the sheet prints. */
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

    // ------------------------------------------------------------ the sheet

    public function test_the_ppk_sheet_carries_the_vendor_band_lines_and_plafon_totals(): void
    {
        $ppk = $this->workOrder();

        $html = $this->forms->html('ppk-alat-jasa', ['id' => $ppk->id]);

        // The counterparty of a PPK is the rental/jasa vendor, not the owner.
        $this->assertStringContainsString('PEMASOK / VENDOR', $html);
        $this->assertStringContainsString('PT Alat Berat Nusantara', $html);
        $this->assertStringContainsString('PROYEK', $html);
        // Blade escapes the ampersand; the sheet itself reads "ALAT & JASA".
        $this->assertStringContainsString('PERINTAH KERJA ALAT &amp; JASA', $html);
        $this->assertStringContainsString('Form F/PPK', $html);
        $this->assertStringContainsString($ppk->code, $html);
        $this->assertStringContainsString('Sewa alat berat tahap struktur apron timur', $html);

        // The lines, their basis spelled by the enum, their plafon and amount.
        $this->assertStringContainsString('Sewa scaffolding lengkap dengan pemasangan', $html);
        $this->assertStringContainsString('Per bulan', $html);
        $this->assertStringContainsString('Per hari (8 jam)', $html);
        $this->assertStringContainsString('15.000.000,00', $html);
        $this->assertStringContainsString('90.000.000,00', $html);
        $this->assertStringContainsString('10.000.000,00', $html);

        // Plafon named as plafon, and every total a stored column: value
        // 100.000.000 + PPN 11% snapshot 11.000.000 = 111.000.000.
        $this->assertStringContainsString('JUMLAH PLAFON (DPP)', $html);
        $this->assertStringContainsString('100.000.000,00', $html);
        $this->assertStringContainsString('PPN 11%', $html);
        $this->assertStringContainsString('11.000.000,00', $html);
        $this->assertStringContainsString('TOTAL PLAFON PPK', $html);
        $this->assertStringContainsString('111.000.000,00', $html);

        // The period the commitment covers.
        $this->assertStringContainsString('1 Juni 2026', $html);
        $this->assertStringContainsString('31 Desember 2026', $html);

        $this->assertStringNotContainsString('null', $html);
    }

    /** Non-PKP vendor: the sheet says PPN is not levied, never "PPN 0%" x 0,00. */
    public function test_a_non_pkp_vendor_prints_ppn_as_not_levied(): void
    {
        $ppk = $this->workOrder([
            'vendor_id' => $this->rentalVendor(['name' => 'CV Sewa Mandiri', 'is_pkp' => false, 'npwp' => null])->id,
        ]);

        $html = $this->forms->html('ppk-alat-jasa', ['id' => $ppk->id]);

        $this->assertStringContainsString('PPN (tidak dikenakan)', $html);
        $this->assertStringNotContainsString('PPN 11%', $html);
        // DPP == total when no PPN is levied.
        $this->assertStringContainsString('100.000.000,00', $html);
    }

    /**
     * The override reason is an audit trail and rides in the notes block —
     * an identity line would print "OVERRIDE PRAKUALIFIKASI : ......" on
     * every clean PPK and invite one to be written in (pola F/PO).
     */
    public function test_a_qualification_override_is_printed_only_when_there_is_one(): void
    {
        $clean = $this->forms->html('ppk-alat-jasa', ['id' => $this->workOrder()->id]);
        $this->assertStringNotContainsString('Override prakualifikasi', $clean);

        $blocked = $this->rentalVendor(['name' => 'PT Rental Nonaktif', 'status' => 'inactive', 'npwp' => '04.567.890.1-045.000']);
        $ppk = $this->workOrder([
            'vendor_id' => $blocked->id,
            'qualification_override_reason' => 'Alat pengganti tidak tersedia; sewa berjalan tidak boleh putus.',
        ]);

        $html = $this->forms->html('ppk-alat-jasa', ['id' => $ppk->id]);

        $this->assertStringContainsString('Override prakualifikasi', $html);
        $this->assertStringContainsString('Alat pengganti tidak tersedia', $html);
    }

    /**
     * A PPK raised without a stated period rules both date lines instead of
     * inventing one — the plafon fences the money, not the calendar.
     */
    public function test_a_ppk_without_dates_rules_its_period_lines(): void
    {
        $ppk = $this->workOrder(['start_date' => null, 'end_date' => null]);

        $html = $this->forms->html('ppk-alat-jasa', ['id' => $ppk->id]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('TANGGAL MULAI'), $html);
        $this->assertMatchesRegularExpression($this->ruledIdentityCell('TANGGAL SELESAI'), $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * An archived lessor keeps its name on the commitment it signed — the
     * withTrashed rule of the class docblock, asserted for this entry.
     */
    public function test_an_archived_vendor_keeps_its_name_on_the_ppk(): void
    {
        $ppk = $this->workOrder();
        $ppk->vendor->delete();

        $html = $this->forms->html('ppk-alat-jasa', ['id' => $ppk->id]);

        $this->assertStringContainsString('PT Alat Berat Nusantara', $html);
    }

    public function test_the_ppk_is_catalogued_for_its_resource(): void
    {
        $catalogue = collect(
            $this->actingAs($this->adminUser())
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('procurement/work-orders', $catalogue['ppk-alat-jasa']['resource']);
        $this->assertSame('id', $catalogue['ppk-alat-jasa']['idField']);
    }
}
