<?php

namespace Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Tax;

/**
 * Master data: Indonesian tax catalogue (PPN, PPh 21/23, PPh final
 * konstruksi PP 9/2022). Safe for production — extracted from
 * FinanceDatabaseSeeder so ProductionSeeder can reuse it. Requires the COA
 * (ChartOfAccountsSeeder) so the coa_account_id lookups resolve.
 * Idempotent (updateOrCreate by code).
 */
class TaxSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'code' => 'PPN',
                'name' => 'PPN',
                'rate' => (float) config('erp.tax.ppn_rate', 11.0),
                'tax_type' => 'ppn',
                'coa_code' => '2-1300',
                'notes' => 'PMK 131/2024: tarif resmi 12% dikenakan atas DPP nilai lain '
                    .'(11/12 dari harga jual/penggantian) untuk BKP/JKP non-mewah, sehingga '
                    .'beban efektif tetap 11% dari harga. Rate disimpan sebagai tarif efektif. '
                    .'PPN Keluaran diposting ke 2-1300, PPN Masukan ke 1-1600.',
            ],
            [
                'code' => 'PPH21',
                'name' => 'PPh 21 Karyawan',
                'rate' => 0.0,
                'tax_type' => 'pph_withholding',
                'coa_code' => '2-1210',
                'notes' => 'Tarif progresif Pasal 17 / TER (PP 58/2023) — dihitung per karyawan '
                    .'di modul payroll, bukan tarif flat; rate 0 hanya placeholder.',
            ],
            [
                'code' => 'PPH23',
                'name' => 'PPh 23 Jasa',
                'rate' => (float) config('erp.tax.pph23_services_rate', 2.0),
                'tax_type' => 'pph_withholding',
                'coa_code' => '2-1220',
                'notes' => 'PPh 23 atas jasa non-konstruksi: 2% dari jumlah bruto (DPP).',
            ],
        ];

        // PPh final jasa konstruksi per PP 9/2022 — one row per scheme so the
        // AP bill for a subcon claim can point at its exact withholding tax.
        $schemeLabels = [
            'pelaksanaan_kecil_bersertifikat' => 'Pelaksanaan — kualifikasi kecil, bersertifikat',
            'pelaksanaan_bersertifikat' => 'Pelaksanaan — bersertifikat (menengah/besar)',
            'pelaksanaan_tanpa_sertifikat' => 'Pelaksanaan — tanpa sertifikat',
            'perancangan_bersertifikat' => 'Perancangan/pengawasan — bersertifikat',
            'perancangan_tanpa_sertifikat' => 'Perancangan/pengawasan — tanpa sertifikat',
            'terintegrasi_bersertifikat' => 'Terintegrasi — bersertifikat',
            'terintegrasi_tanpa_sertifikat' => 'Terintegrasi — tanpa sertifikat',
        ];

        foreach ((array) config('erp.tax.pph_final_construction', []) as $scheme => $rate) {
            $rows[] = [
                'code' => Tax::pphFinalCodeForScheme($scheme),
                'name' => 'PPh Final Konstruksi — '.($schemeLabels[$scheme] ?? $scheme),
                'rate' => (float) $rate,
                'tax_type' => 'pph_withholding',
                'coa_code' => '2-1230',
                'notes' => 'PPh final jasa konstruksi PP 9/2022, dipotong dari DPP penuh opname. '
                    .'Isi kode objek pajak e-Bupot sebelum menyiapkan bukti potong.',
            ];
        }

        // P4 — PPh final UMKM (PP 55/2022 Pasal 56, melanjutkan PP 23/2018):
        // 0,5% dari peredaran bruto. Skema bawaan upah mandor borongan per
        // asumsi #3 — mandor sebagai vendor ber-PPh-final, bukan karyawan.
        // Liabilitasnya PPh final juga, jadi menumpang 2-1230 bersama PP
        // 9/2022 — SSP-nya sama-sama PPh Pasal 4(2)/final.
        $rows[] = [
            'code' => 'PPH4A2-UMKM',
            'name' => 'PPh Final UMKM 0,5% (PP 55/2022) — upah mandor',
            'rate' => (float) config('erp.tax.pph_final_umkm_rate', 0.5),
            'tax_type' => 'pph_withholding',
            'coa_code' => '2-1230',
            'notes' => 'PPh final PP 55/2022 Pasal 56 (0,5% omzet, WP UMKM). Dipakai tagihan opname '
                .'mandor (SP3). Isi kode objek pajak e-Bupot sebelum menyiapkan bukti potong.',
        ];

        foreach ($rows as $row) {
            // object_code is deliberately absent here. Kode objek pajak is issued by
            // DJP and revised from time to time, and it differs per scheme — seeding
            // one guessed value would classify a perancangan withholding as
            // pelaksanaan in a file nobody re-reads before submitting. It is left
            // empty so the export reports it, and it is never overwritten on re-seed
            // because from the first edit onwards it is the tax officer's field.
            Tax::withTrashed()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'rate' => $row['rate'],
                    'tax_type' => $row['tax_type'],
                    'coa_account_id' => Account::query()->where('code', $row['coa_code'])->value('id'),
                    'notes' => $row['notes'],
                ],
            );
        }
    }
}
