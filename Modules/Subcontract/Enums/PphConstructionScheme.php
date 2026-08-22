<?php

namespace Modules\Subcontract\Enums;

use Modules\Core\Support\Erp;

/**
 * PPh final jasa konstruksi classifications per PP 9/2022.
 * Values are the keys of config('erp.tax.pph_final_construction').
 */
enum PphConstructionScheme: string
{
    case PelaksanaanKecilBersertifikat = 'pelaksanaan_kecil_bersertifikat';
    case PelaksanaanBersertifikat = 'pelaksanaan_bersertifikat';
    case PelaksanaanTanpaSertifikat = 'pelaksanaan_tanpa_sertifikat';
    case PerancanganBersertifikat = 'perancangan_bersertifikat';
    case PerancanganTanpaSertifikat = 'perancangan_tanpa_sertifikat';
    case TerintegrasiBersertifikat = 'terintegrasi_bersertifikat';
    case TerintegrasiTanpaSertifikat = 'terintegrasi_tanpa_sertifikat';

    public function label(): string
    {
        return match ($this) {
            self::PelaksanaanKecilBersertifikat => 'Pelaksanaan — kualifikasi kecil, bersertifikat',
            self::PelaksanaanBersertifikat => 'Pelaksanaan — bersertifikat (menengah/besar)',
            self::PelaksanaanTanpaSertifikat => 'Pelaksanaan — tanpa sertifikat',
            self::PerancanganBersertifikat => 'Perancangan/pengawasan — bersertifikat',
            self::PerancanganTanpaSertifikat => 'Perancangan/pengawasan — tanpa sertifikat',
            self::TerintegrasiBersertifikat => 'Terintegrasi — bersertifikat',
            self::TerintegrasiTanpaSertifikat => 'Terintegrasi — tanpa sertifikat',
        };
    }

    /**
     * Statutory PPh final rate (%) for this classification, from config.
     */
    public function rate(): float
    {
        return Erp::float("tax.pph_final_construction.{$this->value}", 0.0);
    }
}
