<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Services\DeploymentService;
use Modules\Assets\Services\EquipmentLogService;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\WorkOrder;
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
        app(EquipmentLogService::class)->record($this->deployment, [
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
