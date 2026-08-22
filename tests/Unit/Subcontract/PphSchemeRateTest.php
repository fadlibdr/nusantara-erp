<?php

namespace Tests\Unit\Subcontract;

use Modules\Subcontract\Enums\PphConstructionScheme;
use Tests\ErpTestCase;

/**
 * PPh final jasa konstruksi (PP 9/2022): the statutory rate behind each
 * classification, and the snapshot SubcontractService takes of it when an SPK
 * is created, so a later rate change never rewrites history on a signed SPK.
 */
class PphSchemeRateTest extends ErpTestCase
{
    use SubcontractFixtures;

    public function test_every_scheme_resolves_its_statutory_rate(): void
    {
        $this->assertPphSchemeRateIsReachable();

        $this->assertSame(1.75, PphConstructionScheme::PelaksanaanKecilBersertifikat->rate());
        $this->assertSame(2.65, PphConstructionScheme::PelaksanaanBersertifikat->rate());
        $this->assertSame(4.0, PphConstructionScheme::PelaksanaanTanpaSertifikat->rate());
        $this->assertSame(3.5, PphConstructionScheme::PerancanganBersertifikat->rate());
        $this->assertSame(6.0, PphConstructionScheme::PerancanganTanpaSertifikat->rate());
        $this->assertSame(2.65, PphConstructionScheme::TerintegrasiBersertifikat->rate());
        $this->assertSame(4.0, PphConstructionScheme::TerintegrasiTanpaSertifikat->rate());
    }

    public function test_a_database_override_beats_the_shipped_rate(): void
    {
        $this->assertPphSchemeRateIsReachable();

        $this->setSetting('tax.pph_final_construction.pelaksanaan_bersertifikat', 3.0);

        $this->assertSame(3.0, PphConstructionScheme::PelaksanaanBersertifikat->rate());
    }

    public function test_creating_an_spk_snapshots_the_rate_of_its_scheme(): void
    {
        $this->assertPphSchemeRateIsReachable();

        $subcontract = $this->subcontractService()->create([
            'vendor_id' => $this->defaultVendor()->id,
            'project_id' => $this->defaultProject()->id,
            'title' => 'Pekerjaan struktur beton',
            'pph_scheme' => PphConstructionScheme::PelaksanaanTanpaSertifikat->value,
            'start_date' => '2026-02-01',
            'end_date' => '2026-08-31',
            'items' => [
                ['description' => 'Pembesian', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 100000000],
            ],
        ]);

        // Tanpa sertifikat = 4,00% (PP 9/2022), snapshotted on the SPK itself.
        $this->assertSame(4.0, (float) $subcontract->pph_rate);
        // Vendor is PKP, so PPN follows the tax parameter.
        $this->assertSame(11.0, (float) $subcontract->ppn_rate);
        $this->assertSame(100000000.0, (float) $subcontract->fresh()->value);
    }

    public function test_a_later_rate_change_does_not_rewrite_an_existing_spk(): void
    {
        $this->assertPphSchemeRateIsReachable();

        $subcontract = $this->subcontractService()->create([
            'vendor_id' => $this->defaultVendor()->id,
            'project_id' => $this->defaultProject()->id,
            'title' => 'Pekerjaan struktur beton',
            'pph_scheme' => PphConstructionScheme::PelaksanaanBersertifikat->value,
            'start_date' => '2026-02-01',
            'end_date' => '2026-08-31',
            'items' => [],
        ]);

        $this->assertSame(2.65, (float) $subcontract->pph_rate);

        $this->setSetting('tax.pph_final_construction.pelaksanaan_bersertifikat', 3.0);

        $this->assertSame(2.65, (float) $subcontract->fresh()->pph_rate);
    }
}
