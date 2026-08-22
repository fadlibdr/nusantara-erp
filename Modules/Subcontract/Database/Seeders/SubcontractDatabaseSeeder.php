<?php

namespace Modules\Subcontract\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\NumberSequence;
use Modules\Procurement\Models\Vendor;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Models\ProgressClaimItem;
use Modules\Subcontract\Models\Subcontract;

class SubcontractDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSubcontracts();
        $this->seedProgressClaims();
        $this->syncNumberSequences();
    }

    /**
     * SPK 1 (approved): pekerjaan struktur Gedung Graha Sentosa, CV Karya Sipil
     * Sejahtera (non-PKP: no PPN), Rp 6.5 M DPP, PPh final 2.65%
     * (pelaksanaan bersertifikat, PP 9/2022), retensi 5%.
     * Line amounts: 1.08 M + 2.05 M + 2.37 M + 1.0 M = 6.5 miliar exactly.
     *
     * SPK 2 (submitted): instalasi ME, PT Mekanika Prima (PKP: PPN 11%),
     * Rp 2.1 M DPP.
     */
    private function seedSubcontracts(): void
    {
        $userId = User::query()->orderBy('id')->value('id');
        $ppnRate = (float) config('erp.tax.ppn_rate', 11.0);
        $threshold = (float) config('erp.approvals.subcontract.threshold_two_level', 200000000);

        $subcontracts = [
            [
                'code' => 'SPK/2026/II/0001',
                'vendor_code' => 'VND-0004',
                'project_code' => 'PRJ-2026-001',
                'title' => 'Pekerjaan Struktur Gedung Kantor Graha Sentosa',
                'scope' => 'Borongan pekerjaan struktur bawah & atas: galian basement, pengecoran beton K-300, '
                    .'pembesian, dan bekisting kolom-balok-plat lantai 1 s.d. 8 sesuai gambar for-construction. '
                    .'Material beton ready mix & besi disuplai kontraktor utama kecuali disebut lain per item.',
                'pph_scheme' => 'pelaksanaan_bersertifikat',
                'retention_pct' => 5,
                'start_date' => '2026-03-01',
                'end_date' => '2026-11-30',
                // Masa pemeliharaan 6 bulan setelah selesai — the notes below
                // always said so; this is the date the retention time gate reads.
                'defect_liability_until' => '2027-05-31',
                'notes' => 'Opname bulanan setiap akhir bulan; retensi dibayar setelah masa pemeliharaan 6 bulan (BAST II).',
                'status' => 'approved',
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                    ['action' => 'approved', 'note' => 'Nilai di atas threshold; disetujui berjenjang s.d. Direktur.'],
                ],
                'items' => [
                    ['wbs_code' => 'B.1', 'description' => 'Galian tanah basement & pondasi', 'qty' => 12000, 'unit' => 'm3', 'unit_price' => 90000],
                    ['wbs_code' => 'B.2', 'description' => 'Pengecoran beton ready mix K-300 kolom, balok & plat (upah & alat)', 'qty' => 8200, 'unit' => 'm3', 'unit_price' => 250000],
                    ['wbs_code' => 'B.3', 'description' => 'Pembesian besi beton ulir (upah borong)', 'qty' => 948000, 'unit' => 'kg', 'unit_price' => 2500],
                    ['wbs_code' => 'B.4', 'description' => 'Bekisting kolom, balok & plat lantai (upah + material bekisting)', 'qty' => 40000, 'unit' => 'm2', 'unit_price' => 25000],
                ],
            ],
            [
                'code' => 'SPK/2026/III/0002',
                'vendor_code' => 'VND-0005',
                'project_code' => 'PRJ-2026-001',
                'title' => 'Instalasi Mekanikal & Elektrikal Gedung Graha Sentosa',
                'scope' => 'Borongan penuh (material + jasa) instalasi plumbing, HVAC VRV, dan listrik arus kuat '
                    .'lantai 1 s.d. 8 termasuk testing & commissioning.',
                'pph_scheme' => 'pelaksanaan_bersertifikat',
                'retention_pct' => 5,
                'start_date' => '2026-05-01',
                'end_date' => '2027-01-31',
                'defect_liability_until' => '2027-07-31', // masa pemeliharaan 6 bulan
                'notes' => 'Menunggu persetujuan Direktur.',
                'status' => 'submitted',
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                ],
                'items' => [
                    ['wbs_code' => 'M.1', 'description' => 'Instalasi plumbing & sanitary lantai 1-8', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 650000000],
                    ['wbs_code' => 'M.2', 'description' => 'Instalasi HVAC (VRV & ducting)', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 850000000],
                    ['wbs_code' => 'M.3', 'description' => 'Instalasi listrik arus kuat (panel, kabel & armatur)', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 600000000],
                ],
            ],
        ];

        foreach ($subcontracts as $data) {
            $vendor = Vendor::query()->where('code', $data['vendor_code'])->first();

            if (! $vendor) {
                continue; // Procurement not seeded yet
            }

            // Real math: line amounts -> SPK value (DPP); tax rates snapshot
            // exactly as SubcontractService does at creation time.
            $lines = [];
            $value = 0.0;

            foreach ($data['items'] as $i => $item) {
                $amount = round($item['qty'] * $item['unit_price'], 2);
                $value = round($value + $amount, 2);
                $lines[] = [
                    'line_no' => $i + 1,
                    'boq_item_id' => $this->boqItemId('BOQ/2026/0001', $item['wbs_code']),
                    'wbs_code' => $item['wbs_code'],
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $amount,
                    'progress_pct' => 0,
                ];
            }

            $subcontract = Subcontract::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'vendor_id' => $vendor->id,
                    'project_id' => $this->lookupId('prj_projects', $data['project_code']),
                    'title' => $data['title'],
                    'scope' => $data['scope'],
                    'value' => $value,
                    'ppn_rate' => $vendor->is_pkp ? $ppnRate : 0.0, // non-PKP: no faktur pajak, no PPN
                    'retention_pct' => $data['retention_pct'],
                    'pph_scheme' => $data['pph_scheme'],
                    // Snapshot of the PP 9/2022 statutory rate at creation.
                    'pph_rate' => (float) config("erp.tax.pph_final_construction.{$data['pph_scheme']}", 0.0),
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'defect_liability_until' => $data['defect_liability_until'] ?? null,
                    'needs_director_approval' => $value >= $threshold,
                    'notes' => $data['notes'],
                    'status' => $data['status'],
                ],
            );

            // Claim items reference SPK items (FK, no cascade): clear them
            // before rebuilding the lines so re-running stays idempotent.
            ProgressClaimItem::query()
                ->whereIn('progress_claim_id', $subcontract->claims()->withTrashed()->pluck('id'))
                ->delete();
            $subcontract->items()->delete();

            foreach ($lines as $line) {
                $subcontract->items()->create($line);
            }

            $this->writeApprovalTrail($subcontract, $data['trail'], $userId);
        }
    }

    /**
     * Two approved opnames on SPK 1, every line claimed uniformly.
     *
     * Opname 1 (0% -> 15%):  gross 975,000,000   = 15% x 6.5 M
     *   retensi 5%           =  48,750,000
     *   net before tax       = 926,250,000
     *   PPN                  =           0 (vendor non-PKP)
     *   PPh final 2.65%      =  25,837,500 (on the FULL gross DPP)
     *   net payable          = 900,412,500
     *
     * Opname 2 (15% -> 32%): gross 1,105,000,000 = 17% x 6.5 M
     *   retensi 5%           =    55,250,000
     *   net before tax       = 1,049,750,000
     *   PPN                  =             0
     *   PPh final 2.65%      =    29,282,500
     *   net payable          = 1,020,467,500
     *
     * Retention balance after both: 104,000,000 (unreleased — masa
     * pemeliharaan still running, so no scm_retention_releases rows).
     */
    private function seedProgressClaims(): void
    {
        $subcontract = Subcontract::query()->where('code', 'SPK/2026/II/0001')->first();

        if (! $subcontract || $subcontract->items()->doesntExist()) {
            return;
        }

        $userId = User::query()->orderBy('id')->value('id');

        $claims = [
            [
                'code' => 'CLM/2026/IV/0001',
                'claim_no' => 1,
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
                'notes' => 'Opname 1: progres fisik struktur 15% (galian & pondasi rampung sebagian, mulai kolom lantai 1).',
                'progress' => ['B.1' => [0, 15], 'B.2' => [0, 15], 'B.3' => [0, 15], 'B.4' => [0, 15]],
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                    ['action' => 'approved', 'note' => 'Volume terpasang sesuai berita acara opname bersama.'],
                ],
            ],
            [
                'code' => 'CLM/2026/V/0002',
                'claim_no' => 2,
                'period_start' => '2026-04-01',
                'period_end' => '2026-04-30',
                'notes' => 'Opname 2: progres kumulatif 32% (struktur basement selesai, kolom-balok lantai 2 berjalan).',
                'progress' => ['B.1' => [15, 32], 'B.2' => [15, 32], 'B.3' => [15, 32], 'B.4' => [15, 32]],
                'trail' => [
                    ['action' => 'submitted', 'note' => null],
                    ['action' => 'approved', 'note' => 'Volume terpasang sesuai berita acara opname bersama.'],
                ],
            ],
        ];

        $items = $subcontract->items()->get()->keyBy('wbs_code');
        $finalProgress = [];

        foreach ($claims as $data) {
            // Same math as ClaimService::recalcTotals, line by line.
            $lines = [];
            $gross = 0.0;

            foreach ($data['progress'] as $wbs => [$prev, $current]) {
                $item = $items->get($wbs);

                if (! $item) {
                    continue;
                }

                $period = round($current - $prev, 4);
                $amount = round($period / 100 * (float) $item->amount, 2);
                $gross = round($gross + $amount, 2);

                $lines[] = [
                    'subcontract_item_id' => $item->id,
                    'prev_progress_pct' => $prev,
                    'current_progress_pct' => $current,
                    'period_progress_pct' => $period,
                    'amount' => $amount,
                ];

                $finalProgress[$item->id] = $current;
            }

            $retention = round($gross * (float) $subcontract->retention_pct / 100, 2);
            $netBeforeTax = round($gross - $retention, 2);
            // PPN & PPh final on the FULL gross DPP — retention defers
            // payment, it does not reduce the tax base (PP 9/2022).
            $ppn = round($gross * (float) $subcontract->ppn_rate / 100, 2);
            $pph = round($gross * (float) $subcontract->pph_rate / 100, 2);

            $claim = ProgressClaim::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'subcontract_id' => $subcontract->id,
                    'claim_no' => $data['claim_no'],
                    'period_start' => $data['period_start'],
                    'period_end' => $data['period_end'],
                    'gross_amount' => $gross,
                    'retention_amount' => $retention,
                    'net_before_tax' => $netBeforeTax,
                    'ppn_amount' => $ppn,
                    'pph_amount' => $pph,
                    'net_payable' => round($netBeforeTax + $ppn - $pph, 2),
                    'notes' => $data['notes'],
                    'status' => 'approved',
                ],
            );

            $claim->items()->delete();

            foreach ($lines as $line) {
                $claim->items()->create($line);
            }

            $this->writeApprovalTrail($claim, $data['trail'], $userId);
        }

        // Approved opnames bump cumulative progress on the SPK lines.
        foreach ($finalProgress as $itemId => $pct) {
            $subcontract->items()->whereKey($itemId)->update(['progress_pct' => $pct]);
        }
    }

    /**
     * Rebuild the approval trail idempotently so re-running the seeder does
     * not duplicate rows.
     */
    private function writeApprovalTrail(Subcontract|ProgressClaim $document, array $trail, ?int $userId): void
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
     * SPK lines trace back to the RAB: resolve est_boq_items by BOQ code +
     * wbs_code; null when Estimation is not migrated/seeded (column nullable
     * by design).
     */
    private function boqItemId(string $boqCode, string $wbsCode): ?int
    {
        if (! Schema::hasTable('est_boq_items') || ! Schema::hasTable('est_boqs')) {
            return null;
        }

        $id = DB::table('est_boq_items')
            ->join('est_boqs', 'est_boqs.id', '=', 'est_boq_items.boq_id')
            ->where('est_boqs.code', $boqCode)
            ->where('est_boq_items.wbs_code', $wbsCode)
            ->value('est_boq_items.id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Cross-module lookup by canonical seed code; null when the owning module
     * has not been migrated/seeded yet.
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
     * past them so runtime-generated SPK/CLM numbers never collide.
     */
    private function syncNumberSequences(): void
    {
        foreach (['SPK', 'CLM'] as $type) {
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
