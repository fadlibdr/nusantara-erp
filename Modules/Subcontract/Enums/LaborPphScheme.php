<?php

namespace Modules\Subcontract\Enums;

use Modules\Core\Support\Erp;

/**
 * Skema pemotongan PPh untuk upah mandor borongan (SP3 — P4), konfigurabel
 * per kontrak per asumsi #3 roadmap "agar pembalikan murah" bila pemilik
 * kelak memilih PPh 21.
 *
 * SENGAJA BUKAN PphConstructionScheme: SP3 mandor bukan jasa konstruksi
 * bersertifikat PP 9/2022 — mandor borongan perorangan dipotong PPh final
 * UMKM PP 55/2022 Pasal 56 (0,5% peredaran bruto, melanjutkan PP 23/2018),
 * dan itulah "PPh final" yang dimaksud asumsi #3.
 *
 * Pph21Ter adalah PINTU YANG BELUM DIBANGUN, dengan jujur: nilai enumnya ada
 * supaya kolom dan API sudah benar bentuknya, tetapi memilihnya ditolak 422
 * "belum diaktifkan" (LaborContractService::assertSchemeActive). Bila pemilik
 * memutuskan PPh 21, jalur itu memakai mesin payroll yang SUDAH ADA —
 * Modules\HrPayroll\Services\Pph21TerService (TER PMK 168/2023) — bukan tarif
 * flat baru; membangunnya setengah-setengah sekarang berarti dua mesin PPh 21
 * yang bisa tidak sepakat.
 */
enum LaborPphScheme: string
{
    case FinalUmkm = 'final_umkm';
    case Pph21Ter = 'pph21_ter';

    public function label(): string
    {
        return match ($this) {
            self::FinalUmkm => 'PPh Final UMKM 0,5% (PP 55/2022)',
            self::Pph21Ter => 'PPh 21 TER (mesin payroll — belum diaktifkan)',
        };
    }

    /**
     * Tarif potong (%) yang di-snapshot ke SP3 saat dibuat. Pph21Ter tidak
     * punya tarif flat — TER dihitung per penerima oleh mesin payroll — dan
     * tidak pernah sampai ke sini (assertSchemeActive menolak lebih dulu).
     */
    public function rate(): float
    {
        return match ($this) {
            self::FinalUmkm => Erp::float('tax.pph_final_umkm_rate', 0.5),
            self::Pph21Ter => 0.0,
        };
    }

    /**
     * Kode baris fin_taxes yang menampung potongan skema ini pada tagihan
     * AP (TaxSeeder menanamnya; 2-1230 Hutang PPh Final).
     */
    public function taxCode(): ?string
    {
        return match ($this) {
            self::FinalUmkm => 'PPH4A2-UMKM',
            self::Pph21Ter => null,
        };
    }
}
