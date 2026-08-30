<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;
use Modules\Assets\Services\DeploymentService;
use Modules\Assets\Services\EquipmentLogService;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Services\ApBillService;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\WorkOrder;
use Modules\Procurement\Models\WorkOrderBillingLine;
use Modules\Procurement\Services\WorkOrderBillingService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P5 — tagihan per periode atas PPK (deviasi 3.10 "rekap tagihan alat tak
 * terikat periode sewa").
 *
 * Aritmetika per_jam yang dipin dengan angka yang bisa dicek tangan:
 * pembacaan 1.200,0 → 1.207,5 → 1.213,0 DI DALAM periode = 13,0 jam.
 * Aturan batasnya: HANYA pembacaan di dalam periode yang dihitung
 * (last-in-period minus first-in-period) — pembacaan sebelum periode tidak
 * pernah membocorkan jam dari luar ke dalam tagihan.
 */
class WorkOrderBillingTest extends ErpTestCase
{
    private User $admin;

    private Project $project;

    private Vendor $vendor;

    private Asset $asset;

    private Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminUser();
        Sanctum::actingAs($this->admin);

        $this->project = Project::create(['name' => 'Gedung Kantor', 'type' => 'construction']);
        $this->vendor = Vendor::create([
            'name' => 'PT Alat Berat Nusantara',
            'classification' => 'jasa',
            'vendor_type' => 'rental',
            'is_pkp' => false,
            'status' => 'active',
        ]);

        $category = AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 60],
        );

        $this->asset = Asset::create([
            'name' => 'Excavator PC200 sewa',
            'category_id' => $category->id,
            'ownership' => 'rented',
            'vendor_id' => $this->vendor->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'useful_life_months' => 0,
            'status' => 'available',
        ]);

        $this->deployment = app(DeploymentService::class)->deploy($this->asset, [
            'project_id' => $this->project->id,
            'deployed_from' => '2026-06-01',
        ]);
    }

    private function billings(): WorkOrderBillingService
    {
        return app(WorkOrderBillingService::class);
    }

    private function log(string $date, float $meter): void
    {
        $this->logOn($this->deployment, $date, $meter);
    }

    private function logOn(Deployment $deployment, string $date, float $meter): void
    {
        app(EquipmentLogService::class)->record($deployment, [
            'log_date' => $date,
            'hour_meter' => $meter,
        ], $this->admin);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function approvedWorkOrder(array $items, array $attributes = []): WorkOrder
    {
        /** @var WorkOrder $workOrder */
        $workOrder = WorkOrder::create(array_merge([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'title' => 'Sewa alat berat tahap struktur',
            'value' => 0,
            'ppn_rate' => 0,
            'start_date' => '2026-06-01',
            'status' => DocumentStatus::Draft,
        ], $attributes));

        $lineNo = 0;
        $value = 0.0;

        foreach ($items as $item) {
            $amount = round((float) $item['rate'] * (float) $item['qty_periods'], 2);
            $value += $amount;

            $workOrder->items()->create($item + ['line_no' => ++$lineNo, 'amount' => $amount]);
        }

        $workOrder->forceFill(['value' => round($value, 2), 'status' => DocumentStatus::Approved])->save();

        return $workOrder;
    }

    private function perJamWorkOrder(float $qtyPeriods = 100): WorkOrder
    {
        return $this->approvedWorkOrder([[
            'description' => 'Sewa excavator PC200',
            'asset_id' => $this->asset->id,
            'rate_basis' => 'per_jam',
            'rate' => 350_000,
            'qty_periods' => $qtyPeriods,
        ]]);
    }

    public function test_tagihan_per_jam_dari_delta_hour_meter_dalam_periode(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-15', 1207.5);
        $this->log('2026-07-31', 1213.0);

        $billing = $this->billings()->create($this->perJamWorkOrder(), [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        // 1.213,0 - 1.200,0 = 13,0 jam x Rp 350.000 = Rp 4.550.000.
        $this->assertSame('4550000.00', (string) $billing->total_amount);
        $this->assertStringStartsWith('PPKB/', $billing->code);

        $line = $billing->lines->first();
        $this->assertSame(13.0, (float) $line->qty);
        $this->assertSame(1200.0, (float) $line->meter_start);
        $this->assertSame(1213.0, (float) $line->meter_end);
    }

    public function test_pembacaan_sebelum_periode_tidak_membocorkan_jam_dari_luar(): void
    {
        // Pembacaan 30 Juni ada DI LUAR periode Juli. Kalau aturan batasnya
        // "pembacaan pertama dalam periode minus pembacaan terakhir sebelum
        // periode", 1.213,0 - 1.195,0 = 18 jam — 5 jam di antaranya berjalan
        // sebelum 1 Juli dan bukan milik tagihan Juli.
        $this->log('2026-06-30', 1195.0);
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-15', 1207.5);
        $this->log('2026-07-31', 1213.0);

        $billing = $this->billings()->create($this->perJamWorkOrder(), [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $this->assertSame(13.0, (float) $billing->lines->first()->qty);
        $this->assertSame('4550000.00', (string) $billing->total_amount);
    }

    public function test_alat_ulang_alik_hanya_menagih_segmen_mobilisasi_proyek_sendiri(): void
    {
        // Repro verbatim kebocoran jam lintas-mobilisasi: alat dimobilisasi ke
        // proyek A, ditarik ke proyek B di tengah periode, lalu kembali ke A.
        $projectB = Project::create(['name' => 'Instalasi ELV Bank', 'type' => 'construction']);
        $deployments = app(DeploymentService::class);

        // Segmen A-1 (mobilisasi setUp): 2026-07-02 = 100,0 → 2026-07-08 = 110,0.
        $this->log('2026-07-02', 100.0);
        $this->log('2026-07-08', 110.0);
        $deployments->returnDeployment($this->deployment, '2026-07-10');

        // Selingan di proyek B: 2026-07-12 = 120,0 → 2026-07-18 = 145,0.
        $deploymentB = $deployments->deploy($this->asset->refresh(), [
            'project_id' => $projectB->id,
            'deployed_from' => '2026-07-11',
        ]);
        $this->logOn($deploymentB, '2026-07-12', 120.0);
        $this->logOn($deploymentB, '2026-07-18', 145.0);
        $deployments->returnDeployment($deploymentB, '2026-07-20');

        // Segmen A-2 (remobilisasi ke proyek A): 2026-07-22 = 150,0 → 2026-07-31 = 160,0.
        $deploymentA2 = $deployments->deploy($this->asset->refresh(), [
            'project_id' => $this->project->id,
            'deployed_from' => '2026-07-21',
        ]);
        $this->logOn($deploymentA2, '2026-07-22', 150.0);
        $this->logOn($deploymentA2, '2026-07-31', 160.0);

        $billing = $this->billings()->create($this->perJamWorkOrder(), [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        // Per segmen mobilisasi proyek A: (110,0 − 100,0) + (160,0 − 150,0)
        // = 10 + 10 = 20 jam. Last-minus-first LINTAS mobilisasi akan memberi
        // 160,0 − 100,0 = 60 jam — 35 jam di antaranya (110,0 → 145,0) berjalan
        // di proyek B atau di perjalanan, dan PPK proyek B menagihnya lagi.
        $line = $billing->lines->first();
        $this->assertSame(20.0, (float) $line->qty);
        $this->assertSame('7000000.00', (string) $billing->total_amount); // 20 jam x Rp 350.000

        // Dua segmen menyumbang: tidak ada SATU pasang pembacaan yang jujur
        // menceritakan 20 jam, jadi snapshot meternya jujur kosong.
        $this->assertNull($line->meter_start);
        $this->assertNull($line->meter_end);

        // PPK proyek B menagih segmennya sendiri: 145,0 − 120,0 = 25 jam —
        // dan HANYA itu. 20 + 25 = 45 jam tertagih total; jam perjalanan
        // 110→120 dan 145→150 tidak tertagih di mana pun (tak terukur).
        $workOrderB = $this->approvedWorkOrder([[
            'description' => 'Sewa excavator PC200',
            'asset_id' => $this->asset->id,
            'rate_basis' => 'per_jam',
            'rate' => 350_000,
            'qty_periods' => 100,
        ]], ['project_id' => $projectB->id]);

        $billingB = $this->billings()->create($workOrderB, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $this->assertSame(25.0, (float) $billingB->lines->first()->qty);
    }

    public function test_ppk_lain_yang_menagih_jam_alat_sama_periode_beririsan_ditolak(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-20', 1210.0);
        $this->log('2026-07-31', 1213.0);

        // PPK lama masih approved setelah renegosiasi tarif melahirkan PPK
        // baru atas alat dan proyek yang sama — jam Juli (13,0: 1.200,0 →
        // 1.213,0) sudah tertagih lewat PPK lama. Tanpa guard lintas-PPK,
        // PPK baru menurunkan 1.213,0 − 1.210,0 = 3,0 jam untuk 15 Jul–15 Agu
        // — 3 jam yang PERSIS sama sudah ada di dalam 13 jam PPK lama.
        $old = $this->perJamWorkOrder();
        $this->billings()->create($old, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $renegotiated = $this->perJamWorkOrder();

        $response = $this->postJson('/api/procurement/work-order-billings', [
            'work_order_id' => $renegotiated->id,
            'period_start' => '2026-07-15',
            'period_end' => '2026-08-15',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString($old->code, (string) $response->json('message'));
        $this->assertSame(0, $renegotiated->billings()->count());
    }

    public function test_arah_sebaliknya_ppk_lama_pun_ditolak_menagih_di_atas_ppk_baru(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-20', 1210.0);
        $this->log('2026-07-31', 1213.0);

        // Jalur merah kedua guard lintas-PPK, dari arah SEBALIKNYA dan lewat
        // lapisan service: PPK baru menagih Juli lebih dulu; PPK lama (masih
        // approved) lalu mencoba periode beririsan. Guard yang sama harus
        // menolak dengan menyebut kode PPK baru — kalau tidak, jam yang sama
        // tertagih dua kali hanya karena urutan pengetikannya terbalik.
        $old = $this->perJamWorkOrder();
        $renegotiated = $this->perJamWorkOrder();

        $this->billings()->create($renegotiated, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        try {
            $this->billings()->create($old, [
                'period_start' => '2026-07-15',
                'period_end' => '2026-08-15',
            ]);
            $this->fail('PPK lama seharusnya ditolak menagih periode yang jamnya sudah ditagih PPK baru.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($renegotiated->code, $e->getMessage());
        }

        $this->assertSame(0, $old->billings()->count());
    }

    public function test_dua_ppk_menagih_paruh_periode_berbeda_tetap_sah(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-10', 1207.5);
        $this->log('2026-07-16', 1210.0);
        $this->log('2026-07-31', 1213.0);

        // Dua PPK approved atas alat+proyek yang sama BOLEH hidup berdampingan
        // selama periode berjam-nya saling lepas — yang ditolak irisan periode,
        // bukan sekadar adanya PPK lain.
        $old = $this->perJamWorkOrder();
        $renegotiated = $this->perJamWorkOrder();

        $first = $this->billings()->create($old, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-15',
        ]);
        $second = $this->billings()->create($renegotiated, [
            'period_start' => '2026-07-16',
            'period_end' => '2026-07-31',
        ]);

        $this->assertSame(7.5, (float) $first->lines->first()->qty); // 1.207,5 − 1.200,0
        $this->assertSame(3.0, (float) $second->lines->first()->qty); // 1.213,0 − 1.210,0
    }

    public function test_tagihan_per_bulan_dari_kalender_dan_periode_tak_utuh_ditolak(): void
    {
        $workOrder = $this->approvedWorkOrder([[
            'description' => 'Sewa scaffolding lengkap',
            'rate_basis' => 'per_bulan',
            'rate' => 15_000_000,
            'qty_periods' => 6,
        ]]);

        $billing = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $this->assertSame(1.0, (float) $billing->lines->first()->qty);
        $this->assertSame('15000000.00', (string) $billing->total_amount);

        // Periode yang bukan bulan kalender utuh ditolak untuk basis bulanan.
        try {
            $this->billings()->create($workOrder, [
                'period_start' => '2026-08-05',
                'period_end' => '2026-09-04',
            ]);
            $this->fail('Periode per_bulan yang tidak utuh seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('bulan kalender utuh', $e->getMessage());
        }
    }

    public function test_tagihan_per_hari_8jam_dari_kalender(): void
    {
        $workOrder = $this->approvedWorkOrder([[
            'description' => 'Sewa genset 100 kVA',
            'rate_basis' => 'per_hari_8jam',
            'rate' => 800_000,
            'qty_periods' => 90,
        ]]);

        $billing = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-10',
        ]);

        // 10 hari kalender inklusif x Rp 800.000.
        $this->assertSame(10.0, (float) $billing->lines->first()->qty);
        $this->assertSame('8000000.00', (string) $billing->total_amount);
    }

    public function test_periode_yang_sama_atau_tumpang_tindih_ditolak_dua_arah(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-31', 1213.0);

        $workOrder = $this->perJamWorkOrder();

        $first = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        // Arah 1: periode identik.
        // Arah 2: periode baru menjorok ke dalam yang sudah tertagih.
        // Arah 3: periode baru MEMBUNGKUS yang sudah tertagih.
        foreach ([
            ['2026-07-01', '2026-07-31'],
            ['2026-07-15', '2026-08-15'],
            ['2026-06-15', '2026-08-15'],
        ] as [$start, $end]) {
            try {
                $this->billings()->create($workOrder, [
                    'period_start' => $start,
                    'period_end' => $end,
                ]);
                $this->fail("Periode {$start}..{$end} tumpang-tindih dan seharusnya ditolak.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('tumpang-tindih', $e->getMessage());
                $this->assertStringContainsString($first->code, $e->getMessage());
            }
        }

        $this->assertSame(1, $workOrder->billings()->count());
    }

    public function test_endpoint_menolak_periode_tumpang_tindih_dengan_422(): void
    {
        // Jalur merah KEDUA untuk guard tumpang-tindih, independen dari uji
        // level service di atas: guard harus berdiri juga di jahitan HTTP —
        // LogicException dipetakan ke 422 bernarasi, bukan 500. Jendela yang
        // tumpang-tindih SENGAJA memuat pembacaan terukur (15 & 31 Jul =
        // 5,5 jam): kalau guard-nya hilang, endpoint akan 201 membuat tagihan
        // ganda — bukan tersandung "tidak ada kuantitas".
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-15', 1207.5);
        $this->log('2026-07-31', 1213.0);

        $workOrder = $this->perJamWorkOrder();
        $first = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $response = $this->postJson('/api/procurement/work-order-billings', [
            'work_order_id' => $workOrder->id,
            'period_start' => '2026-07-15',
            'period_end' => '2026-08-15',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('tumpang-tindih', (string) $response->json('message'));
        $this->assertStringContainsString($first->code, (string) $response->json('message'));
        $this->assertSame(1, $workOrder->billings()->count());
    }

    public function test_ganti_meter_antar_mobilisasi_menjumlah_delta_per_segmen(): void
    {
        // Hour-meter diganti (atau alat pengganti dengan register baru) di
        // antara dua mobilisasi ke proyek yang SAMA: mobilisasi 1 mencatat
        // 100,0 → 110,0 lalu demobilisasi; mobilisasi 2 mulai dari 5,0 → 20,0.
        // Aturan per segmen: 10 + 15 = 25 jam. Last-minus-first LINTAS
        // mobilisasi akan menghitung 20,0 − 100,0 = −80 dan menolak register
        // yang sebenarnya sah — angka mundur ANTAR segmen bukan data korup,
        // melainkan meter baru.
        $this->log('2026-07-02', 100.0);
        $this->log('2026-07-08', 110.0);

        $deployments = app(DeploymentService::class);
        $deployments->returnDeployment($this->deployment, '2026-07-10');

        $replacement = $deployments->deploy($this->asset->refresh(), [
            'project_id' => $this->project->id,
            'deployed_from' => '2026-07-11',
        ]);
        $this->logOn($replacement, '2026-07-12', 5.0);
        $this->logOn($replacement, '2026-07-20', 20.0);

        $billing = $this->billings()->create($this->perJamWorkOrder(), [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $line = $billing->lines->first();
        $this->assertSame(25.0, (float) $line->qty);
        $this->assertSame('8750000.00', (string) $billing->total_amount); // 25 jam x Rp 350.000

        // Dua segmen menyumbang → snapshot meter jujur kosong (tidak ada satu
        // pasang angka yang menceritakan 25 jam).
        $this->assertNull($line->meter_start);
        $this->assertNull($line->meter_end);
    }

    public function test_register_mundur_di_dalam_satu_mobilisasi_menolak_penagihan(): void
    {
        // EquipmentLogService menolak pembacaan mundur SAAT pencatatan, jadi
        // pasangan menurun di dalam satu mobilisasi hanya bisa lahir dari
        // register yang korup (edit langsung di basis data, migrasi data
        // lama). Fixture ini menanamnya langsung lewat model — sabuk pengaman
        // di sisi penagihan harus berdiri sendiri, tidak bersandar pada guard
        // register.
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-15', 1210.0);

        EquipmentLog::query()
            ->where('deployment_id', $this->deployment->id)
            ->whereDate('log_date', '2026-07-15')
            ->update(['hour_meter' => 1190.0]);

        $workOrder = $this->perJamWorkOrder();

        try {
            $this->billings()->create($workOrder, [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
            ]);
            $this->fail('Register yang mundur di dalam satu mobilisasi seharusnya menolak penagihan.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('mundur di dalam periode', $e->getMessage());
            $this->assertStringContainsString($this->deployment->code, $e->getMessage());
        }

        $this->assertSame(0, $workOrder->billings()->count());
    }

    public function test_plafon_qty_periods_per_baris_menolak_kelebihan(): void
    {
        // Kontrak 2 bulan; bulan ketiga melebihi plafon barisnya.
        $workOrder = $this->approvedWorkOrder([[
            'description' => 'Sewa scaffolding lengkap',
            'rate_basis' => 'per_bulan',
            'rate' => 15_000_000,
            'qty_periods' => 2,
        ]]);

        $this->billings()->create($workOrder, ['period_start' => '2026-06-01', 'period_end' => '2026-06-30']);
        $this->billings()->create($workOrder, ['period_start' => '2026-07-01', 'period_end' => '2026-07-31']);

        try {
            $this->billings()->create($workOrder, ['period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
            $this->fail('Kuantitas melebihi plafon qty_periods seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi sisa', $e->getMessage());
        }

        $this->assertSame(2, $workOrder->billings()->count());
    }

    public function test_ppk_belum_approved_tidak_bisa_ditagih(): void
    {
        $workOrder = $this->perJamWorkOrder();
        $workOrder->forceFill(['status' => DocumentStatus::Draft])->save();

        try {
            $this->billings()->create($workOrder, [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
            ]);
            $this->fail('Billing atas PPK draft seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('disetujui', $e->getMessage());
        }
    }

    public function test_periode_tanpa_kuantitas_terukur_ditolak(): void
    {
        // Satu pembacaan saja: tidak ada delta yang bisa diukur, dan tidak
        // ada baris kalender yang menagih — menagih nol adalah tagihan kosong.
        $this->log('2026-07-02', 1200.0);

        try {
            $this->billings()->create($this->perJamWorkOrder(), [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
            ]);
            $this->fail('Billing tanpa kuantitas terukur seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak ada kuantitas', $e->getMessage());
        }
    }

    public function test_billing_dengan_tagihan_ap_hidup_menolak_dihapus(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-31', 1213.0);

        $workOrder = $this->perJamWorkOrder();
        $billing = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $bill = app(ApBillService::class)->create([
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-070',
        ]);

        // Uangnya sudah berangkat lewat AP: hapus ditolak 422 dengan MENYEBUT
        // tagihan AP-nya — operator tahu persis dokumen mana yang harus
        // dibatalkan lebih dulu.
        $response = $this->deleteJson("/api/procurement/work-order-billings/{$billing->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString($bill->code, (string) $response->json('message'));
        $this->assertStringContainsString('batalkan', (string) $response->json('message'));

        $this->assertSame(1, $workOrder->billings()->count());
        $this->assertSame(1, WorkOrderBillingLine::query()->where('work_order_billing_id', $billing->id)->count());
    }

    public function test_tagihan_ap_batal_membebaskan_billing_untuk_dihapus_dan_ditagih_ulang(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-31', 1213.0);

        $workOrder = $this->perJamWorkOrder();
        $billing = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $bill = app(ApBillService::class)->create([
            'work_order_billing_id' => $billing->id,
            'vendor_invoice_no' => 'INV-ABN-070',
        ]);

        // Tagihan yang DIBATALKAN tidak lagi menahan: pembatalan membalik
        // jurnalnya, menghapus billing membebaskan periodenya.
        $bill->forceFill(['status' => DocumentStatus::Cancelled])->save();

        $this->deleteJson("/api/procurement/work-order-billings/{$billing->id}")->assertOk();

        $this->assertSame(0, $workOrder->billings()->count());
        $this->assertSame(0, WorkOrderBillingLine::query()->where('work_order_billing_id', $billing->id)->count());

        // Periodenya bebas lagi: penyusunan ulang atas periode yang sama
        // menurunkan angka yang sama dari register yang tidak berubah.
        $again = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $this->assertSame(13.0, (float) $again->lines->first()->qty);
        $this->assertSame(1, $workOrder->billings()->count());
        $this->assertNotSame($billing->id, $again->id);
    }

    public function test_rekap_tagihan_alat_terikat_periode(): void
    {
        $this->log('2026-07-02', 1200.0);
        $this->log('2026-07-31', 1213.0);

        $workOrder = $this->perJamWorkOrder();
        $billing = $this->billings()->create($workOrder, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $response = $this->getJson('/api/procurement/work-orders/reports/billing-recap?from=2026-07-01&to=2026-07-31')
            ->assertOk();

        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame($billing->code, $rows[0]['billing_code']);
        $this->assertSame($workOrder->code, $rows[0]['work_order_code']);
        $this->assertSame('2026-07-01', $rows[0]['period_start']);
        $this->assertSame('2026-07-31', $rows[0]['period_end']);
        // Belum ada tagihan AP: kolomnya jujur kosong, bukan "BIL tercatat".
        $this->assertNull($rows[0]['ap_bill_code']);

        // Rekap terikat periode: jendela Agustus tidak memuat billing Juli.
        $empty = $this->getJson('/api/procurement/work-orders/reports/billing-recap?from=2026-08-01&to=2026-08-31')
            ->assertOk();
        $this->assertCount(0, $empty->json('data.rows'));
    }
}
