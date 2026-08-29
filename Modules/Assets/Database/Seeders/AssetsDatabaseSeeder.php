<?php

namespace Modules\Assets\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Enums\DeploymentStatus;
use Modules\Assets\Enums\MaintenanceType;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\DepreciationRun;
use Modules\Assets\Models\Maintenance;
use Modules\Assets\Services\DepreciationService;
use Modules\Core\Models\NumberSequence;

class AssetsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Categories live in a dedicated seeder so ProductionSeeder can run
        // them without the demo assets/deployments/depreciation below.
        $this->call(AssetCategorySeeder::class);

        $this->seedAssets();
        $this->seedRentedAsset();
        $this->seedDeployments();
        $this->seedRentedDeploymentAndLogs();
        $this->seedMaintenance();
        $this->seedJuneDepreciationRun();
        $this->syncNumberSequences();
    }

    /**
     * P5 — satu alat SEWA di register (deviasi 3.6 "milik sendiri saja").
     * Tanpa harga perolehan dan tanpa nilai buku (NULL, bukan Rp 0 — alat ini
     * bukan milik kita), tanpa kolom penyusutan; tarifnya per jam mengikuti
     * PPK/2026/VI/0001 di seeder Procurement.
     *
     * KEMBARAN: ProcurementDatabaseSeeder::rentedDemoAssetId meng-updateOrCreate
     * baris AST-0007 yang SAMA dengan muatan yang sama (pola menara GSP-T1,
     * CONVENTIONS §8) karena Procurement diseed lebih dulu dan baris per_jam
     * PPK demo harus menunjuk alat ini. Mana pun yang jalan lebih dulu yang
     * membuatnya; ubah muatannya = ubah KEDUA seeder. Yang di sini menambah
     * custodian (EMP-0003) — pengayaan deskriptif, bukan fakta finansial.
     */
    private function seedRentedAsset(): void
    {
        $categoryId = AssetCategory::query()->where('code', 'ALAT-BERAT')->value('id');

        if ($categoryId === null) {
            return;
        }

        $asset = Asset::withTrashed()->firstOrNew(['code' => 'AST-0007']);

        $asset->fill([
            'name' => 'Excavator Doosan DX225LCA (sewa)',
            'category_id' => $categoryId,
            'brand' => 'Doosan',
            'model' => 'DX225LCA',
            'serial_no' => 'DSN-DX225-88413',
            'ownership' => 'rented',
            'vendor_id' => $this->lookupId('prc_vendors', 'VND-0007'),
            'rental_rate' => 400000,
            'rate_basis' => 'per_jam',
            'rental_start' => '2026-06-01',
            'rental_end' => '2026-12-31',
            'custodian_employee_id' => $this->lookupId('hr_employees', 'EMP-0003'),
        ]);

        if (! $asset->exists) {
            $asset->fill([
                'acquisition_date' => null,
                'acquisition_cost' => null,
                'salvage_value' => 0,
                'useful_life_months' => 0,
                'depreciation_start_date' => null,
                'accumulated_depreciation' => 0,
                'book_value' => null,
                'status' => AssetStatus::Available,
            ]);
        }

        $asset->save();
    }

    /**
     * Mobilisasi alat sewa ke site Graha Sentosa + dua pembacaan hour-meter
     * Juli 2026 (3.240,0 → 3.375,5 = 135,5 jam) — sumber angka tagihan
     * periode PPKB/2026/VII/0001 di seeder Procurement dan baris sewa di
     * layar Evaluasi Sewa vs Beli. daily_rate_internal SENGAJA null: beban
     * alat sewa masuk lewat tagihan AP vendornya (PPK), dan akrual internal
     * di atasnya akan menghitung solar yang sama dua kali.
     */
    private function seedRentedDeploymentAndLogs(): void
    {
        $code = 'DEP/2026/VI/0004';

        if (Deployment::withTrashed()->where('code', $code)->exists()) {
            return;
        }

        $asset = Asset::query()->where('code', 'AST-0007')->first();
        $projectId = $this->lookupId('prj_projects', 'PRJ-2026-001');
        $userId = DB::table('users')->orderBy('id')->value('id');

        if (! $asset || $projectId === null || $userId === null) {
            return;
        }

        $deployment = new Deployment([
            'asset_id' => $asset->id,
            'project_id' => $projectId,
            'deployed_from' => '2026-06-01',
            'planned_until' => '2026-12-31',
            'returned_at' => null,
            'daily_rate_internal' => null,
            'notes' => 'Mobilisasi excavator sewa (PPK VND-0007) untuk galian & timbunan tahap struktur.',
            'status' => DeploymentStatus::Active,
        ]);
        $deployment->code = $code;
        $deployment->save();

        $asset->forceFill([
            'status' => AssetStatus::Deployed,
            'current_project_id' => $projectId,
        ])->save();

        foreach ([
            ['log_date' => '2026-07-01', 'hour_meter' => 3240.0, 'notes' => 'Pembacaan awal periode Juli.'],
            ['log_date' => '2026-07-31', 'hour_meter' => 3375.5, 'notes' => 'Pembacaan akhir periode Juli.'],
        ] as $reading) {
            $deployment->equipmentLogs()->create($reading + ['logged_by' => $userId]);
        }
    }

    /**
     * Six realistic assets. Accumulated depreciation is seeded through May 2026
     * (monthly = (cost - salvage) / life, same rounding as DepreciationService),
     * so the June 2026 run posted below adds exactly one more month.
     */
    private function seedAssets(): void
    {
        $assets = [
            [
                // monthly (850jt - 130jt)/60 = 12.000.000; Jan 2025 - May 2026 = 17 months
                'code' => 'AST-0001',
                'name' => 'Excavator Komatsu PC200-8 (bekas)',
                'category' => 'ALAT-BERAT',
                'brand' => 'Komatsu',
                'model' => 'PC200-8',
                'serial_no' => 'KMTPC2008C45721',
                'acquisition_date' => '2024-12-15',
                'acquisition_cost' => 850000000,
                'salvage_value' => 130000000,
                'useful_life_months' => 60,
                'depreciation_start_date' => '2025-01-01',
                'accumulated_depreciation' => 204000000,
                'custodian' => 'EMP-0003',
                'warehouse' => null,
            ],
            [
                // monthly (420jt - 60jt)/60 = 6.000.000; Mar 2025 - May 2026 = 15 months
                'code' => 'AST-0002',
                'name' => 'Dump Truck Hino Dutro 130 HD',
                'category' => 'KENDARAAN',
                'brand' => 'Hino',
                'model' => 'Dutro 130 HD',
                'serial_no' => 'MJEC1JG43P5061884',
                'acquisition_date' => '2025-02-20',
                'acquisition_cost' => 420000000,
                'salvage_value' => 60000000,
                'useful_life_months' => 60,
                'depreciation_start_date' => '2025-03-01',
                'accumulated_depreciation' => 90000000,
                'custodian' => 'EMP-0003',
                'warehouse' => null,
            ],
            [
                // monthly (85jt - 13jt)/48 = 1.500.000; Jul 2025 - May 2026 = 11 months
                'code' => 'AST-0003',
                'name' => 'Total Station Topcon GM-52',
                'category' => 'ALAT-UKUR',
                'brand' => 'Topcon',
                'model' => 'GM-52',
                'serial_no' => 'TP-GM52-118427',
                'acquisition_date' => '2025-06-18',
                'acquisition_cost' => 85000000,
                'salvage_value' => 13000000,
                'useful_life_months' => 48,
                'depreciation_start_date' => '2025-07-01',
                'accumulated_depreciation' => 16500000,
                'custodian' => 'EMP-0008',
                'warehouse' => 'WH-PUSAT',
            ],
            [
                // monthly (120jt - 0)/48 = 2.500.000; Jan 2026 - May 2026 = 5 months
                'code' => 'AST-0004',
                'name' => 'Scaffolding Set Ringlock 400 m2',
                'category' => 'ALAT-BERAT',
                'brand' => null,
                'model' => 'Ringlock Galvanis',
                'serial_no' => null,
                'acquisition_date' => '2025-12-10',
                'acquisition_cost' => 120000000,
                'salvage_value' => 0,
                'useful_life_months' => 48,
                'depreciation_start_date' => '2026-01-01',
                'accumulated_depreciation' => 12500000,
                'custodian' => 'EMP-0003',
                'warehouse' => 'WH-PUSAT',
            ],
            [
                // monthly (65jt - 5jt)/48 = 1.250.000; Oct 2025 - May 2026 = 8 months
                'code' => 'AST-0005',
                'name' => 'Fusion Splicer Sumitomo T-72C',
                'category' => 'ALAT-UKUR',
                'brand' => 'Sumitomo',
                'model' => 'T-72C',
                'serial_no' => 'SMT72C-230944',
                'acquisition_date' => '2025-09-25',
                'acquisition_cost' => 65000000,
                'salvage_value' => 5000000,
                'useful_life_months' => 48,
                'depreciation_start_date' => '2025-10-01',
                'accumulated_depreciation' => 10000000,
                'custodian' => 'EMP-0007',
                'warehouse' => null,
            ],
            [
                // monthly (95jt - 5jt)/48 = 1.875.000; Mar 2026 - May 2026 = 3 months
                'code' => 'AST-0006',
                'name' => 'Server Rack 42U + UPS 10kVA',
                'category' => 'PERALATAN-IT',
                'brand' => 'APC',
                'model' => 'NetShelter SX / Smart-UPS SRT10K',
                'serial_no' => 'APC-SRT10K-77120',
                'acquisition_date' => '2026-02-12',
                'acquisition_cost' => 95000000,
                'salvage_value' => 5000000,
                'useful_life_months' => 48,
                'depreciation_start_date' => '2026-03-01',
                'accumulated_depreciation' => 5625000,
                'custodian' => 'EMP-0007',
                'warehouse' => 'WH-PUSAT',
            ],
        ];

        foreach ($assets as $data) {
            $categoryId = AssetCategory::query()->where('code', $data['category'])->value('id');

            if ($categoryId === null) {
                continue;
            }

            $asset = Asset::withTrashed()->firstOrNew(['code' => $data['code']]);

            $descriptive = [
                'name' => $data['name'],
                'category_id' => $categoryId,
                'brand' => $data['brand'],
                'model' => $data['model'],
                'serial_no' => $data['serial_no'],
                'custodian_employee_id' => $this->lookupId('hr_employees', $data['custodian']),
                'warehouse_id' => $this->lookupId('inv_warehouses', $data['warehouse']),
            ];

            if (! $asset->exists) {
                // Financial state only on first seed — re-running must never
                // rewind accumulated depreciation after the June run posted.
                $asset->fill($descriptive + [
                    'acquisition_date' => $data['acquisition_date'],
                    'acquisition_cost' => $data['acquisition_cost'],
                    'salvage_value' => $data['salvage_value'],
                    'useful_life_months' => $data['useful_life_months'],
                    'depreciation_start_date' => $data['depreciation_start_date'],
                    'accumulated_depreciation' => $data['accumulated_depreciation'],
                    'book_value' => round($data['acquisition_cost'] - $data['accumulated_depreciation'], 2),
                    'status' => AssetStatus::Available,
                ]);
            } else {
                $asset->fill($descriptive);
            }

            $asset->save();
        }
    }

    /**
     * Three active mobilizations: excavator & dump truck at the Graha Sentosa
     * building site, fusion splicer at the Bank Artha data-center project.
     */
    private function seedDeployments(): void
    {
        $deployments = [
            [
                'code' => 'DEP/2026/III/0001',
                'asset_code' => 'AST-0001',
                'project_code' => 'PRJ-2026-001',
                'deployed_from' => '2026-03-02',
                'planned_until' => '2026-11-30',
                'daily_rate_internal' => 2500000,
                'notes' => 'Mobilisasi excavator untuk galian basement & pondasi Gedung Graha Sentosa.',
            ],
            [
                'code' => 'DEP/2026/III/0002',
                'asset_code' => 'AST-0002',
                'project_code' => 'PRJ-2026-001',
                'deployed_from' => '2026-03-02',
                'planned_until' => '2026-11-30',
                'daily_rate_internal' => 1000000,
                'notes' => 'Angkutan tanah galian dan material struktur.',
            ],
            [
                'code' => 'DEP/2026/V/0003',
                'asset_code' => 'AST-0005',
                'project_code' => 'PRJ-2026-002',
                'deployed_from' => '2026-05-11',
                'planned_until' => '2026-09-30',
                'daily_rate_internal' => 500000,
                'notes' => 'Penyambungan fiber optic backbone data center Bank Artha.',
            ],
        ];

        foreach ($deployments as $data) {
            if (Deployment::withTrashed()->where('code', $data['code'])->exists()) {
                continue;
            }

            $asset = Asset::query()->where('code', $data['asset_code'])->first();
            $projectId = $this->lookupId('prj_projects', $data['project_code']);

            if (! $asset || $projectId === null) {
                continue; // Projects module not seeded yet — skip gracefully
            }

            $deployment = new Deployment([
                'asset_id' => $asset->id,
                'project_id' => $projectId,
                'deployed_from' => $data['deployed_from'],
                'planned_until' => $data['planned_until'],
                'returned_at' => null,
                'daily_rate_internal' => $data['daily_rate_internal'],
                'notes' => $data['notes'],
                'status' => DeploymentStatus::Active,
            ]);
            $deployment->code = $data['code'];
            $deployment->save();

            // Mirror DeploymentService::deploy() side effects on the asset.
            $asset->forceFill([
                'status' => AssetStatus::Deployed,
                'current_project_id' => $projectId,
            ])->save();
        }
    }

    private function seedMaintenance(): void
    {
        $code = 'MTC/2026/VI/0001';

        $existing = Maintenance::withTrashed()->where('code', $code)->first();

        if ($existing) {
            // Heal the vendor link on databases seeded before Procurement ran.
            if ($existing->vendor_id === null) {
                $vendorId = $this->lookupId('prc_vendors', 'VND-0005');

                if ($vendorId !== null) {
                    $existing->forceFill(['vendor_id' => $vendorId])->save();
                }
            }

            return;
        }

        $asset = Asset::query()->where('code', 'AST-0001')->first();

        if (! $asset) {
            return;
        }

        $maintenance = new Maintenance([
            'asset_id' => $asset->id,
            'maintenance_date' => '2026-06-14',
            'maintenance_type' => MaintenanceType::ServiceRutin,
            'vendor_id' => $this->lookupId('prc_vendors', 'VND-0005'),
            'cost' => 18500000,
            'description' => 'Service 2.000 jam di site: ganti oli hidrolik & filter, pengecekan undercarriage dan attachment bucket.',
            'next_due_date' => '2026-12-14',
        ]);
        $maintenance->code = $code;
        $maintenance->save();
    }

    /**
     * June 2026 depreciation drafted and posted through the real service, so
     * entry amounts, run total and asset book values come from the actual
     * straight-line math (total: Rp 25.125.000 across the six assets).
     */
    private function seedJuneDepreciationRun(): void
    {
        $exists = DepreciationRun::query()
            ->where('period_year', 2026)
            ->where('period_month', 6)
            ->exists();

        if ($exists) {
            return; // already seeded — posting twice would double-depreciate
        }

        if (Asset::query()->doesntExist()) {
            return;
        }

        $service = app(DepreciationService::class);
        $run = $service->runForPeriod(2026, 6);

        // The HasDocumentNumber trait stamped a number from the seed date; pin
        // the canonical June-period code so the demo dataset is deterministic.
        $run->code = 'DPR/2026/06/001';
        $run->save();

        $service->post($run);
    }

    /**
     * Seeded codes use explicit sequence numbers; push the counters past them
     * so runtime-generated AST/DEP/MTC/DPR numbers never collide with the canon.
     */
    private function syncNumberSequences(): void
    {
        foreach (['AST' => 7, 'DEP' => 4, 'MTC' => 1, 'DPR' => 1] as $type => $lastNumber) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => 2026],
                ['last_number' => 0],
            );

            if ((int) $sequence->last_number < $lastNumber) {
                $sequence->update(['last_number' => $lastNumber]);
            }
        }
    }

    /**
     * Cross-module lookup by canonical seed code; null when the owning module
     * has not been migrated/seeded yet (columns are nullable by design).
     */
    private function lookupId(string $table, ?string $code): ?int
    {
        if ($code === null || ! Schema::hasTable($table)) {
            return null;
        }

        $id = DB::table($table)->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
