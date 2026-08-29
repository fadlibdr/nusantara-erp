<?php

namespace Modules\Procurement\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\NumberSequence;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorEvaluation;
use Modules\Procurement\Services\VendorEvaluationService;

class ProcurementDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedVendors();
        $this->seedPurchaseRequisitions();
        $this->seedPurchaseOrders();
        $this->seedVendorEvaluations();
        $this->syncNumberSequences();
    }

    private function seedVendors(): void
    {
        $vendors = [
            [
                'code' => 'VND-0001',
                'name' => 'PT Semen Distribusi Utama',
                'legal_name' => 'PT Semen Distribusi Utama',
                'npwp' => '01.334.556.7-007.000',
                'is_pkp' => true,
                'sppkp_number' => 'S-212PKP/WPJ.20/KP.0803/2019',
                'is_subcontractor' => false,
                'classification' => 'material',
                'address' => 'Jl. Raya Bekasi Km 21, Pulogadung',
                'city' => 'Jakarta Timur',
                'phone' => '021-4602-8811',
                'email' => 'sales@semendistribusi.co.id',
                'pic_name' => 'Herman Wibowo',
                'bank_name' => 'BCA',
                'bank_account_no' => '5230118899',
                'bank_account_name' => 'PT Semen Distribusi Utama',
                'payment_term_days' => 30,
                'status' => 'active',
            ],
            [
                'code' => 'VND-0002',
                'name' => 'CV Baja Mandiri',
                'legal_name' => 'CV Baja Mandiri',
                'npwp' => '02.445.667.8-407.000',
                'is_pkp' => true,
                'sppkp_number' => 'S-108PKP/WPJ.22/KP.0403/2021',
                'is_subcontractor' => false,
                'classification' => 'material',
                'address' => 'Jl. Industri Selatan Blok GG-5, Kawasan Jababeka II',
                'city' => 'Bekasi',
                'phone' => '021-8983-4522',
                'email' => 'penjualan@bajamandiri.co.id',
                'pic_name' => 'Lina Kartika',
                'bank_name' => 'Mandiri',
                'bank_account_no' => '1560009812334',
                'bank_account_name' => 'CV Baja Mandiri',
                'payment_term_days' => 30,
                'status' => 'active',
            ],
            [
                'code' => 'VND-0003',
                'name' => 'PT Elektrindo Supply',
                'legal_name' => 'PT Elektrindo Supply',
                'npwp' => '01.556.778.9-073.000',
                'is_pkp' => true,
                'sppkp_number' => 'S-334PKP/WPJ.06/KP.1103/2018',
                'is_subcontractor' => false,
                'classification' => 'ict',
                'address' => 'Komplek Harco Mangga Dua Blok C No. 12',
                'city' => 'Jakarta Pusat',
                'phone' => '021-6220-7733',
                'email' => 'order@elektrindosupply.co.id',
                'pic_name' => 'Vincent Tanuwijaya',
                'bank_name' => 'BCA',
                'bank_account_no' => '3410225577',
                'bank_account_name' => 'PT Elektrindo Supply',
                'payment_term_days' => 45,
                'status' => 'active',
            ],
            [
                'code' => 'VND-0004',
                'name' => 'CV Karya Sipil Sejahtera',
                'legal_name' => 'CV Karya Sipil Sejahtera',
                'npwp' => '02.667.889.0-412.000',
                'is_pkp' => false, // small CV, cannot issue faktur pajak
                'sppkp_number' => null,
                'is_subcontractor' => true,
                'classification' => 'sipil',
                'address' => 'Jl. Margonda Raya No. 310',
                'city' => 'Depok',
                'phone' => '021-7720-4185',
                'email' => 'karyasipilsejahtera@gmail.com',
                'pic_name' => 'H. Syamsul Bahri',
                'bank_name' => 'BRI',
                'bank_account_no' => '032101002211504',
                'bank_account_name' => 'CV Karya Sipil Sejahtera',
                'payment_term_days' => 14,
                'status' => 'active',
            ],
            [
                'code' => 'VND-0005',
                'name' => 'PT Mekanika Prima',
                'legal_name' => 'PT Mekanika Prima',
                'npwp' => '01.778.990.1-402.000',
                'is_pkp' => true,
                'sppkp_number' => 'S-517PKP/WPJ.08/KP.0203/2020',
                'is_subcontractor' => true,
                'classification' => 'me',
                'address' => 'Ruko Bidex Blok F-9, BSD City',
                'city' => 'Tangerang Selatan',
                'phone' => '021-5316-0244',
                'email' => 'admin@mekanikaprima.co.id',
                'pic_name' => 'Ir. Dodi Firmansyah',
                'bank_name' => 'Mandiri',
                'bank_account_no' => '1280071133456',
                'bank_account_name' => 'PT Mekanika Prima',
                'payment_term_days' => 30,
                'status' => 'active',
            ],
            [
                // P4 — vendor bertipe MANDOR (upah borongan, SP3). Perorangan
                // non-PKP; PPh final UMKM 0,5% (PP 55/2022) dipotong pada
                // tagihan opname mandornya.
                'code' => 'VND-0006',
                'name' => 'Mandor Harjo Wibowo',
                'legal_name' => 'Harjo Wibowo',
                'npwp' => '09.123.456.7-412.000',
                'is_pkp' => false,
                'sppkp_number' => null,
                'is_subcontractor' => false,
                'vendor_type' => 'mandor',
                'classification' => 'jasa',
                'address' => 'Kp. Rawa Bebek RT 04/02, Cakung',
                'city' => 'Jakarta Timur',
                'phone' => '0813-8020-4471',
                'email' => null,
                'pic_name' => 'Harjo Wibowo',
                'bank_name' => 'BRI',
                'bank_account_no' => '032201009945021',
                'bank_account_name' => 'Harjo Wibowo',
                'payment_term_days' => 7,
                'status' => 'active',
            ],
        ];

        foreach ($vendors as $vendor) {
            Vendor::withTrashed()->updateOrCreate(
                ['code' => $vendor['code']],
                $vendor,
            );
        }

        $this->seedMandorDocuments();
    }

    /**
     * P4 — register dokumen mandor demo: K3L + pakta integritas (tanpa
     * keduanya gate prakualifikasi menolak SP3-nya — penyempitan P0-E berlaku
     * juga untuk mandor) dan CV Mandor, sumber lembar F/CVM.
     */
    private function seedMandorDocuments(): void
    {
        $mandor = Vendor::query()->where('code', 'VND-0006')->first();

        if (! $mandor) {
            return;
        }

        $documents = [
            ['doc_type' => 'k3l_commitment', 'name' => 'Komitmen K3L Mandor Harjo', 'number' => null, 'valid_until' => null, 'is_mandatory' => true],
            ['doc_type' => 'pakta_integritas', 'name' => 'Pakta Integritas Mandor Harjo', 'number' => null, 'valid_until' => null, 'is_mandatory' => true],
            ['doc_type' => 'cv_mandor', 'name' => 'CV Mandor Harjo Wibowo — borongan sipil sejak 2011', 'number' => 'CV/HW/2026', 'valid_until' => null, 'is_mandatory' => false],
        ];

        foreach ($documents as $document) {
            $mandor->documents()->updateOrCreate(
                ['doc_type' => $document['doc_type'], 'name' => $document['name']],
                $document,
            );
        }
    }

    private function seedPurchaseRequisitions(): void
    {
        $userId = User::query()->orderBy('id')->value('id');

        $requisitions = [
            [
                'code' => 'PR/2026/II/0001',
                'project_code' => 'PRJ-2026-001',
                'warehouse_code' => 'WH-PRJ-2026-001',
                'needed_date' => '2026-03-02',
                'purpose' => 'Kebutuhan material struktur (semen & besi beton) pekerjaan kolom-balok lantai 1-2 Gedung Graha Sentosa.',
                'notes' => 'Prioritas: jadwal pengecoran mulai minggu pertama Maret.',
                'status' => 'approved',
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                    ['action' => 'approved', 'note' => 'Sesuai RAP struktur; lanjut proses PO.'],
                ],
                'items' => [
                    ['item_code' => 'ITM-0001', 'description' => 'Semen Portland 50kg', 'qty' => 2000, 'unit' => 'zak', 'estimated_price' => 62000],
                    ['item_code' => 'ITM-0002', 'description' => 'Besi Beton D16', 'qty' => 1500, 'unit' => 'btg', 'estimated_price' => 145000],
                ],
            ],
            [
                'code' => 'PR/2026/III/0002',
                'project_code' => 'PRJ-2026-002',
                'warehouse_code' => 'WH-PRJ-2026-002',
                'needed_date' => '2026-04-01',
                'purpose' => 'Material ELV/ICT tahap 1 (CCTV, switching, kabel) instalasi kantor cabang Bank Artha Nusantara.',
                'notes' => 'Volume untuk 4 cabang pertama sesuai jadwal rollout.',
                'status' => 'submitted',
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                ],
                'items' => [
                    ['item_code' => 'ITM-0004', 'description' => 'CCTV Dome 4MP', 'qty' => 24, 'unit' => 'unit', 'estimated_price' => 1850000],
                    ['item_code' => 'ITM-0006', 'description' => 'Switch Managed 24 Port', 'qty' => 6, 'unit' => 'unit', 'estimated_price' => 4200000],
                    ['item_code' => 'ITM-0003', 'description' => 'Kabel UTP Cat6', 'qty' => 40, 'unit' => 'roll', 'estimated_price' => 1150000],
                ],
            ],
        ];

        foreach ($requisitions as $data) {
            $pr = PurchaseRequisition::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'project_id' => $this->lookupId('prj_projects', $data['project_code']),
                    'warehouse_id' => $this->lookupId('inv_warehouses', $data['warehouse_code']),
                    'requested_by' => $userId,
                    'needed_date' => $data['needed_date'],
                    'purpose' => $data['purpose'],
                    'notes' => $data['notes'],
                    'status' => $data['status'],
                ],
            );

            $pr->items()->delete();

            foreach ($data['items'] as $i => $item) {
                $pr->items()->create([
                    'line_no' => $i + 1,
                    'item_id' => $this->lookupId('inv_items', $item['item_code']),
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'estimated_price' => $item['estimated_price'],
                    'boq_item_id' => null,
                ]);
            }

            $this->writeApprovalTrail($pr, $data['trail'], $userId);
        }
    }

    private function seedPurchaseOrders(): void
    {
        $userId = User::query()->orderBy('id')->value('id');
        $ppnRate = (float) config('erp.tax.ppn_rate', 11.0);
        $threshold = (float) config('erp.approvals.purchase_order.threshold_two_level', 100000000);

        $orders = [
            [
                'code' => 'PO/2026/II/0001',
                'vendor_code' => 'VND-0001',
                'pr_code' => 'PR/2026/II/0001',
                'project_code' => 'PRJ-2026-001',
                'warehouse_code' => 'WH-PRJ-2026-001',
                'order_date' => '2026-02-16',
                'expected_date' => '2026-03-01',
                'delivery_address' => 'Site Proyek Gedung Graha Sentosa, Jl. TB Simatupang Kav. 18, Jakarta Selatan',
                'notes' => 'Semen sesuai PR/2026/II/0001; pasir beton ditambahkan untuk kebutuhan site. Besi beton dipesan terpisah ke CV Baja Mandiri.',
                'status' => 'approved',
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                    ['action' => 'approved', 'note' => 'Di atas threshold: disetujui berjenjang s.d. Direktur.'],
                ],
                'items' => [
                    ['item_code' => 'ITM-0001', 'description' => 'Semen Portland 50kg', 'qty' => 2000, 'unit' => 'zak', 'unit_price' => 62000],
                    ['item_code' => 'ITM-0005', 'description' => 'Pasir Beton', 'qty' => 300, 'unit' => 'm3', 'unit_price' => 285000],
                ],
            ],
            [
                'code' => 'PO/2026/III/0002',
                'vendor_code' => 'VND-0003',
                'pr_code' => null, // direct PO (PR ICT masih submitted)
                'project_code' => 'PRJ-2026-002',
                'warehouse_code' => 'WH-PRJ-2026-002',
                'order_date' => '2026-03-09',
                'expected_date' => '2026-03-23',
                'delivery_address' => 'Gudang Site Bank Artha, Menara Artha Basement 2, Jl. Jend. Sudirman Kav. 34, Jakarta Pusat',
                'notes' => 'Material ELV/ICT tahap 1 untuk 4 cabang pertama. Garansi resmi principal, DOA replacement 1x24 jam.',
                'status' => 'approved',
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                    ['action' => 'approved', 'note' => 'Di atas threshold: disetujui berjenjang s.d. Direktur.'],
                ],
                'items' => [
                    ['item_code' => 'ITM-0004', 'description' => 'CCTV Dome 4MP', 'qty' => 24, 'unit' => 'unit', 'unit_price' => 1850000],
                    ['item_code' => 'ITM-0006', 'description' => 'Switch Managed 24 Port', 'qty' => 6, 'unit' => 'unit', 'unit_price' => 4200000],
                    ['item_code' => 'ITM-0003', 'description' => 'Kabel UTP Cat6', 'qty' => 40, 'unit' => 'roll', 'unit_price' => 1150000],
                ],
            ],
        ];

        foreach ($orders as $data) {
            $vendor = Vendor::query()->where('code', $data['vendor_code'])->first();

            if (! $vendor) {
                continue;
            }

            // Real math: line amounts -> subtotal -> dpp -> ppn (PKP only) -> total.
            $lines = [];
            $subtotal = 0.0;

            foreach ($data['items'] as $i => $item) {
                $amount = round($item['qty'] * $item['unit_price'], 2);
                $subtotal = round($subtotal + $amount, 2);
                $lines[] = [
                    'line_no' => $i + 1,
                    'item_id' => $this->lookupId('inv_items', $item['item_code']),
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $amount,
                    'qty_received' => 0,
                ];
            }

            $dpp = $subtotal; // no discount on seeded POs
            $rate = $vendor->is_pkp ? $ppnRate : 0.0;
            $ppnAmount = round($dpp * $rate / 100, 2);
            $total = round($dpp + $ppnAmount, 2);

            $po = PurchaseOrder::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'vendor_id' => $vendor->id,
                    'purchase_requisition_id' => $data['pr_code'] !== null
                        ? PurchaseRequisition::query()->where('code', $data['pr_code'])->value('id')
                        : null,
                    'project_id' => $this->lookupId('prj_projects', $data['project_code']),
                    'warehouse_id' => $this->lookupId('inv_warehouses', $data['warehouse_code']),
                    'order_date' => $data['order_date'],
                    'expected_date' => $data['expected_date'],
                    'payment_term_days' => $vendor->payment_term_days,
                    'subtotal' => $subtotal,
                    'discount_amount' => 0,
                    'dpp' => $dpp,
                    'ppn_rate' => $rate,
                    'ppn_amount' => $ppnAmount,
                    'total' => $total,
                    'needs_director_approval' => $total >= $threshold,
                    'delivery_address' => $data['delivery_address'],
                    'notes' => $data['notes'],
                    'status' => $data['status'],
                    'closed_at' => null,
                ],
            );

            $po->items()->delete();

            foreach ($lines as $line) {
                $po->items()->create($line);
            }

            $this->writeApprovalTrail($po, $data['trail'], $userId);
        }
    }

    private function seedVendorEvaluations(): void
    {
        $vendor = Vendor::query()->where('code', 'VND-0001')->first();

        if (! $vendor) {
            return;
        }

        $service = app(VendorEvaluationService::class);

        $scores = [
            'quality_score' => 5,
            'delivery_score' => 4,
            'price_score' => 4,
            'service_score' => 5,
        ];

        VendorEvaluation::query()->updateOrCreate(
            ['vendor_id' => $vendor->id, 'period' => '2026-S1'],
            $scores + [
                'project_id' => $this->lookupId('prj_projects', 'PRJ-2026-001'),
                'evaluated_by' => User::query()->orderBy('id')->value('id'),
                'total_score' => $service->totalScore($scores),
                'notes' => 'Kualitas semen konsisten, pengiriman sesekali mundur 1 hari saat cuaca buruk.',
            ],
        );

        $service->refreshVendorRating($vendor);
    }

    /**
     * Rebuild the approval trail idempotently so re-running the seeder does
     * not duplicate rows.
     */
    private function writeApprovalTrail(PurchaseRequisition|PurchaseOrder $document, array $trail, ?int $userId): void
    {
        $document->approvals()->delete();

        foreach ($trail as $entry) {
            $document->approvals()->create([
                'action' => $entry['action'],
                'user_id' => $userId,
                'note' => $entry['note'],
            ]);
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

    /**
     * Seeded codes use explicit sequence numbers 1-2; move the 2026 counters
     * past them so runtime-generated PR/PO numbers never collide with the canon.
     */
    private function syncNumberSequences(): void
    {
        foreach (['PR', 'PO'] as $type) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => 2026],
                ['last_number' => 0],
            );

            if ((int) $sequence->last_number < 2) {
                $sequence->update(['last_number' => 2]);
            }
        }
    }
}
