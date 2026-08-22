<?php

namespace Modules\Estimation\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\NumberSequence;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Services\AhspService;
use Modules\Estimation\Services\BoqService;
use Modules\Estimation\Services\RapService;

class EstimationDatabaseSeeder extends Seeder
{
    /**
     * Demo dataset:
     *  - 8 AHSP analyses (sipil/arsitektur + ELV/ICT) with full component mixes,
     *  - BOQ/2026/0001 (approved) for CTR/2026/I/0001 / PRJ-2026-001 — grand total
     *    Rp 48,5 M = the contract value (DPP, PPN 11% di atasnya),
     *  - BOQ/2026/0002 (draft) for PRJ-2026-002 — ELV & ICT scope, Rp 9,8 M (DPP),
     *  - RAP/2026/0001 (submitted) generated from BOQ/2026/0001 at 15% target margin.
     */
    public function run(AhspService $ahspService, BoqService $boqService, RapService $rapService): void
    {
        $ahspIds = $this->seedAhsp($ahspService);

        // Seeded codes use fixed sequence numbers; advance the counters so
        // user-created documents don't collide with them.
        $this->bumpSequence('BOQ', 2);
        $this->bumpSequence('RAP', 1);

        $boq1 = $this->seedBoqGedungGraha($boqService, $ahspIds);
        $this->seedBoqElvBankArtha($boqService, $ahspIds);
        $this->seedRap($rapService, $boq1);
    }

    /**
     * @return array<string, int> AHSP code => id
     */
    private function seedAhsp(AhspService $ahspService): array
    {
        $definitions = [
            [
                'code' => 'A.2.3.1.1',
                'name' => 'Penggalian 1 m3 tanah biasa sedalam 1 m',
                'unit' => 'm3',
                'category' => 'sipil',
                'overhead_pct' => 10,
                'components' => [
                    ['type' => 'labor', 'name' => 'Pekerja', 'unit' => 'OH', 'coefficient' => 0.75, 'unit_price' => 110000],
                    ['type' => 'labor', 'name' => 'Mandor', 'unit' => 'OH', 'coefficient' => 0.025, 'unit_price' => 165000],
                ],
            ],
            [
                'code' => 'A.4.3.1.3',
                'name' => 'Membuat 1 m3 beton ready mix K-300 (f\'c 26,4 MPa)',
                'unit' => 'm3',
                'category' => 'sipil',
                'overhead_pct' => 10,
                'components' => [
                    ['type' => 'material', 'name' => 'Ready Mix K-300', 'unit' => 'm3', 'coefficient' => 1.02, 'unit_price' => 1150000, 'item' => 'ITM-0007'],
                    ['type' => 'labor', 'name' => 'Pekerja', 'unit' => 'OH', 'coefficient' => 1.0, 'unit_price' => 110000],
                    ['type' => 'labor', 'name' => 'Tukang cor', 'unit' => 'OH', 'coefficient' => 0.25, 'unit_price' => 145000],
                    ['type' => 'labor', 'name' => 'Mandor', 'unit' => 'OH', 'coefficient' => 0.1, 'unit_price' => 165000],
                    ['type' => 'equipment', 'name' => 'Vibrator beton', 'unit' => 'jam', 'coefficient' => 0.5, 'unit_price' => 45000],
                ],
            ],
            [
                'code' => 'A.4.3.1.10',
                'name' => 'Pembesian 1 kg besi beton ulir',
                'unit' => 'kg',
                'category' => 'sipil',
                'overhead_pct' => 10,
                'components' => [
                    // Stocked as ITM-0002 in batang (btg); analysis coefficient stays in kg.
                    ['type' => 'material', 'name' => 'Besi beton ulir D16', 'unit' => 'kg', 'coefficient' => 1.05, 'unit_price' => 12500, 'item' => 'ITM-0002'],
                    ['type' => 'material', 'name' => 'Kawat beton', 'unit' => 'kg', 'coefficient' => 0.015, 'unit_price' => 25000],
                    ['type' => 'labor', 'name' => 'Pekerja', 'unit' => 'OH', 'coefficient' => 0.007, 'unit_price' => 110000],
                    ['type' => 'labor', 'name' => 'Tukang besi', 'unit' => 'OH', 'coefficient' => 0.007, 'unit_price' => 145000],
                    ['type' => 'labor', 'name' => 'Kepala tukang', 'unit' => 'OH', 'coefficient' => 0.001, 'unit_price' => 155000],
                    ['type' => 'labor', 'name' => 'Mandor', 'unit' => 'OH', 'coefficient' => 0.0004, 'unit_price' => 165000],
                ],
            ],
            [
                'code' => 'A.4.1.1.7',
                'name' => 'Pemasangan 1 m2 dinding bata merah 1/2 bata 1:4',
                'unit' => 'm2',
                'category' => 'arsitektur',
                'overhead_pct' => 10,
                'components' => [
                    ['type' => 'material', 'name' => 'Bata merah', 'unit' => 'bh', 'coefficient' => 70, 'unit_price' => 800],
                    ['type' => 'material', 'name' => 'Semen Portland 50kg', 'unit' => 'zak', 'coefficient' => 0.23, 'unit_price' => 75000, 'item' => 'ITM-0001'],
                    ['type' => 'material', 'name' => 'Pasir pasang', 'unit' => 'm3', 'coefficient' => 0.043, 'unit_price' => 350000, 'item' => 'ITM-0005'],
                    ['type' => 'labor', 'name' => 'Pekerja', 'unit' => 'OH', 'coefficient' => 0.3, 'unit_price' => 110000],
                    ['type' => 'labor', 'name' => 'Tukang batu', 'unit' => 'OH', 'coefficient' => 0.1, 'unit_price' => 145000],
                    ['type' => 'labor', 'name' => 'Kepala tukang', 'unit' => 'OH', 'coefficient' => 0.01, 'unit_price' => 155000],
                    ['type' => 'labor', 'name' => 'Mandor', 'unit' => 'OH', 'coefficient' => 0.015, 'unit_price' => 165000],
                ],
            ],
            [
                'code' => 'E.CCTV.01',
                'name' => 'Instalasi 1 titik kamera CCTV dome indoor',
                'unit' => 'ttk',
                'category' => 'elv',
                'overhead_pct' => 10,
                'components' => [
                    ['type' => 'material', 'name' => 'CCTV Dome 4MP', 'unit' => 'unit', 'coefficient' => 1, 'unit_price' => 1850000, 'item' => 'ITM-0004'],
                    ['type' => 'material', 'name' => 'Kabel UTP Cat6', 'unit' => 'roll', 'coefficient' => 0.12, 'unit_price' => 1250000, 'item' => 'ITM-0003'],
                    ['type' => 'material', 'name' => 'Conduit PVC 20mm', 'unit' => 'm', 'coefficient' => 12, 'unit_price' => 8500],
                    ['type' => 'material', 'name' => 'Connector RJ45 & aksesoris', 'unit' => 'set', 'coefficient' => 1, 'unit_price' => 35000],
                    ['type' => 'labor', 'name' => 'Teknisi ELV', 'unit' => 'OH', 'coefficient' => 0.5, 'unit_price' => 175000],
                    ['type' => 'labor', 'name' => 'Helper', 'unit' => 'OH', 'coefficient' => 0.5, 'unit_price' => 110000],
                ],
            ],
            [
                'code' => 'E.NET.01',
                'name' => 'Instalasi 1 titik data LAN Cat6 (outlet)',
                'unit' => 'ttk',
                'category' => 'ict',
                'overhead_pct' => 10,
                'components' => [
                    ['type' => 'material', 'name' => 'Kabel UTP Cat6', 'unit' => 'roll', 'coefficient' => 0.1, 'unit_price' => 1250000, 'item' => 'ITM-0003'],
                    ['type' => 'material', 'name' => 'Faceplate & modular jack Cat6', 'unit' => 'set', 'coefficient' => 1, 'unit_price' => 65000],
                    ['type' => 'material', 'name' => 'Conduit PVC 20mm', 'unit' => 'm', 'coefficient' => 10, 'unit_price' => 8500],
                    ['type' => 'labor', 'name' => 'Teknisi ELV', 'unit' => 'OH', 'coefficient' => 0.35, 'unit_price' => 175000],
                    ['type' => 'labor', 'name' => 'Helper', 'unit' => 'OH', 'coefficient' => 0.35, 'unit_price' => 110000],
                ],
            ],
            [
                'code' => 'E.NET.02',
                'name' => 'Pemasangan switch managed 24 port (terminasi & konfigurasi)',
                'unit' => 'unit',
                'category' => 'ict',
                'overhead_pct' => 10,
                'components' => [
                    ['type' => 'material', 'name' => 'Switch Managed 24 Port', 'unit' => 'unit', 'coefficient' => 1, 'unit_price' => 8500000, 'item' => 'ITM-0006'],
                    ['type' => 'material', 'name' => 'Patch cord & aksesoris rak', 'unit' => 'set', 'coefficient' => 1, 'unit_price' => 450000],
                    ['type' => 'labor', 'name' => 'Teknisi ELV', 'unit' => 'OH', 'coefficient' => 1, 'unit_price' => 175000],
                    ['type' => 'labor', 'name' => 'Network engineer', 'unit' => 'OH', 'coefficient' => 0.5, 'unit_price' => 350000],
                ],
            ],
            [
                'code' => 'E.WIFI.01',
                'name' => 'Instalasi 1 titik access point WiFi 6 termasuk titik data',
                'unit' => 'ttk',
                'category' => 'ict',
                'overhead_pct' => 10,
                'components' => [
                    ['type' => 'material', 'name' => 'Access Point WiFi 6', 'unit' => 'unit', 'coefficient' => 1, 'unit_price' => 3250000, 'item' => 'ITM-0008'],
                    ['type' => 'material', 'name' => 'Kabel UTP Cat6', 'unit' => 'roll', 'coefficient' => 0.12, 'unit_price' => 1250000, 'item' => 'ITM-0003'],
                    ['type' => 'material', 'name' => 'Aksesoris mounting', 'unit' => 'set', 'coefficient' => 1, 'unit_price' => 75000],
                    ['type' => 'labor', 'name' => 'Teknisi ELV', 'unit' => 'OH', 'coefficient' => 0.6, 'unit_price' => 175000],
                    ['type' => 'labor', 'name' => 'Helper', 'unit' => 'OH', 'coefficient' => 0.4, 'unit_price' => 110000],
                ],
            ],
        ];

        $ids = [];

        foreach ($definitions as $definition) {
            $ahsp = Ahsp::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'unit' => $definition['unit'],
                    'category' => $definition['category'],
                    'overhead_pct' => $definition['overhead_pct'],
                ],
            );

            $ahsp->components()->delete();

            foreach ($definition['components'] as $component) {
                $ahsp->components()->create([
                    'component_type' => $component['type'],
                    'name' => $component['name'],
                    'item_id' => isset($component['item']) ? $this->lookupId('inv_items', $component['item']) : null,
                    'unit' => $component['unit'],
                    'coefficient' => $component['coefficient'],
                    'unit_price' => $component['unit_price'],
                ]);
            }

            $ahspService->recalcUnitPrice($ahsp);

            $ids[$definition['code']] = $ahsp->id;
        }

        return $ids;
    }

    /**
     * BOQ/2026/0001 — approved RAB for the Graha Sentosa office building.
     * Grand total Rp 48.500.000.000 = the CTR/2026/I/0001 contract value
     * (DPP, PPN 11% di atasnya).
     *
     * Selling unit prices carry a ~11% commercial mark-up over the AHSP cost
     * analyses (passed explicitly, overriding the AHSP default price) so the
     * RAB lands exactly on the negotiated contract DPP; the closing lump-sum
     * line C.5 absorbs the rounding.
     */
    private function seedBoqGedungGraha(BoqService $boqService, array $ahspIds): Boq
    {
        $boq = Boq::query()->updateOrCreate(
            ['code' => 'BOQ/2026/0001'],
            [
                'title' => 'RAB Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
                'project_id' => $this->lookupId('prj_projects', 'PRJ-2026-001'),
                'contract_id' => $this->lookupId('crm_contracts', 'CTR/2026/I/0001'),
                'version' => 1,
                'status' => DocumentStatus::Draft,
                'notes' => 'Nilai kontrak Rp 48,5 M (DPP), PPN 11% di atasnya.',
            ],
        );

        $boqService->replaceSections($boq, [
            [
                'section_no' => 'A',
                'name' => 'Pekerjaan Persiapan',
                'sort_order' => 1,
                'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Mobilisasi & demobilisasi peralatan', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 388400000],
                    ['wbs_code' => 'A.2', 'description' => 'Direksi keet, pagar sementara & fasilitas proyek', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 499350000],
                ],
            ],
            [
                'section_no' => 'B',
                'name' => 'Pekerjaan Struktur',
                'sort_order' => 2,
                'items' => [
                    ['wbs_code' => 'B.1', 'description' => 'Galian tanah basement & pondasi', 'ahsp_id' => $ahspIds['A.2.3.1.1'], 'qty' => 12000, 'unit_price' => 105740],
                    ['wbs_code' => 'B.2', 'description' => 'Beton ready mix K-300 kolom, balok & plat', 'ahsp_id' => $ahspIds['A.4.3.1.3'], 'qty' => 8200, 'unit_price' => 1657950],
                    ['wbs_code' => 'B.3', 'description' => 'Pembesian besi beton ulir', 'ahsp_id' => $ahspIds['A.4.3.1.10'], 'qty' => 948000, 'unit_price' => 18927.40],
                    ['wbs_code' => 'B.4', 'description' => 'Bekisting kolom, balok & plat lantai', 'qty' => 42000, 'unit' => 'm2', 'unit_price' => 183098],
                ],
            ],
            [
                'section_no' => 'C',
                'name' => 'Pekerjaan Arsitektur & MEP',
                'sort_order' => 3,
                'items' => [
                    ['wbs_code' => 'C.1', 'description' => 'Pasangan dinding bata merah 1/2 bata 1:4', 'ahsp_id' => $ahspIds['A.4.1.1.7'], 'qty' => 16000, 'unit_price' => 170675],
                    ['wbs_code' => 'C.2', 'description' => 'Plesteran & acian dinding 1:4', 'qty' => 32000, 'unit' => 'm2', 'unit_price' => 94846],
                    ['wbs_code' => 'C.3', 'description' => 'Instalasi titik kamera CCTV dome indoor', 'ahsp_id' => $ahspIds['E.CCTV.01'], 'qty' => 120, 'unit_price' => 2782500],
                    ['wbs_code' => 'C.4', 'description' => 'Instalasi titik data LAN Cat6', 'ahsp_id' => $ahspIds['E.NET.01'], 'qty' => 400, 'unit_price' => 457440],
                    // Rounding absorber: brings the grand total to exactly Rp 48,5 M.
                    ['wbs_code' => 'C.5', 'description' => 'Pekerjaan MEP lainnya (plumbing, HVAC, listrik arus kuat)', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 832140800],
                ],
            ],
        ]);

        $boq->forceFill(['status' => DocumentStatus::Approved])->save();

        return $boq;
    }

    /**
     * BOQ/2026/0002 — draft RAB for the Bank Artha ELV & ICT rollout.
     * Grand total Rp 9.800.000.000 = the CTR/2026/II/0002 contract value
     * (DPP, PPN 11% di atasnya).
     *
     * Same pricing convention as BOQ/2026/0001: selling unit prices carry a
     * ~11% commercial mark-up over the AHSP cost analyses; line C.3 absorbs
     * the rounding so the grand total is exact.
     */
    private function seedBoqElvBankArtha(BoqService $boqService, array $ahspIds): Boq
    {
        $boq = Boq::query()->updateOrCreate(
            ['code' => 'BOQ/2026/0002'],
            [
                'title' => 'RAB Instalasi ELV & ICT 12 Cabang Bank Artha Nusantara',
                'project_id' => $this->lookupId('prj_projects', 'PRJ-2026-002'),
                'contract_id' => $this->lookupId('crm_contracts', 'CTR/2026/II/0002'),
                'version' => 1,
                'status' => DocumentStatus::Draft,
                'notes' => 'Nilai kontrak Rp 9,8 M (DPP), PPN 11% di atasnya. 12 cabang + data center.',
            ],
        );

        $boqService->replaceSections($boq, [
            [
                'section_no' => 'A',
                'name' => 'Infrastruktur Jaringan',
                'sort_order' => 1,
                'items' => [
                    ['wbs_code' => 'A.1', 'description' => 'Instalasi titik data LAN Cat6 (12 cabang)', 'ahsp_id' => $ahspIds['E.NET.01'], 'qty' => 1440, 'unit_price' => 457770],
                    ['wbs_code' => 'A.2', 'description' => 'Pemasangan & konfigurasi switch managed 24 port', 'ahsp_id' => $ahspIds['E.NET.02'], 'qty' => 48, 'unit_price' => 11360000],
                    ['wbs_code' => 'A.3', 'description' => 'Instalasi access point WiFi 6', 'ahsp_id' => $ahspIds['E.WIFI.01'], 'qty' => 240, 'unit_price' => 4426850],
                ],
            ],
            [
                'section_no' => 'B',
                'name' => 'Sistem Keamanan (CCTV & Akses Kontrol)',
                'sort_order' => 2,
                'items' => [
                    ['wbs_code' => 'B.1', 'description' => 'Instalasi titik kamera CCTV dome indoor', 'ahsp_id' => $ahspIds['E.CCTV.01'], 'qty' => 480, 'unit_price' => 2784500],
                    ['wbs_code' => 'B.2', 'description' => 'NVR 32 channel & storage per cabang', 'qty' => 12, 'unit' => 'set', 'unit_price' => 72180000],
                    ['wbs_code' => 'B.3', 'description' => 'Sistem akses kontrol pintu (reader, lock, controller)', 'qty' => 96, 'unit' => 'pintu', 'unit_price' => 13880000],
                ],
            ],
            [
                'section_no' => 'C',
                'name' => 'Data Center',
                'sort_order' => 3,
                'items' => [
                    ['wbs_code' => 'C.1', 'description' => 'Rak server 42U, kabel tray & struktur data center', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 943900000],
                    ['wbs_code' => 'C.2', 'description' => 'UPS 60 kVA & precision cooling', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1943350000],
                    // Rounding absorber: brings the grand total to exactly Rp 9,8 M.
                    ['wbs_code' => 'C.3', 'description' => 'Backbone fiber optic antar lantai & antar gedung', 'qty' => 8, 'unit' => 'segmen', 'unit_price' => 138829650],
                ],
            ],
        ]);

        return $boq;
    }

    /**
     * RAP/2026/0001 — internal cost budget generated from BOQ/2026/0001
     * at 15% target margin, then submitted for management approval.
     */
    private function seedRap(RapService $rapService, Boq $boq): void
    {
        $rap = CostBudget::query()->updateOrCreate(
            ['code' => 'RAP/2026/0001'],
            [
                'boq_id' => $boq->id,
                'project_id' => $boq->project_id,
                'target_margin_pct' => 15,
                'status' => DocumentStatus::Draft,
                'notes' => 'RAP internal dari RAB terkontrak Gedung Graha Sentosa, target margin 15%.',
            ],
        );

        $rapService->generateFromBoq($rap);

        $rap->forceFill(['status' => DocumentStatus::Submitted])->save();
    }

    /**
     * Resolve a cross-module row id by its canonical seed code;
     * returns null (graceful skip) when the other module isn't migrated/seeded.
     */
    private function lookupId(string $table, string $code): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $id = DB::table($table)->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Seeded documents carry fixed numbers (e.g. BOQ/2026/0001); push the
     * shared sequence past them so runtime-generated numbers never collide.
     */
    private function bumpSequence(string $type, int $to): void
    {
        $sequence = NumberSequence::query()->firstOrCreate(
            ['type' => $type, 'year' => 2026],
            ['last_number' => 0],
        );

        if ((int) $sequence->last_number < $to) {
            $sequence->last_number = $to;
            $sequence->save();
        }
    }
}
