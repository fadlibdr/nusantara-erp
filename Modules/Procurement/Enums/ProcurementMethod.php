<?php

namespace Modules\Procurement\Enums;

/**
 * Metode pengadaan sebuah paket belanja (Pola Belanja / PBL).
 *
 * Bukan salinan Perpres LKPP (yang untuk belanja pemerintah); ini metode
 * internal kontraktor swasta: apakah paket dibeli langsung, ditunjuk langsung,
 * dibandingkan lewat RFQ, atau ditenderkan.
 */
enum ProcurementMethod: string
{
    case PembelianLangsung = 'pembelian_langsung';
    case PenunjukanLangsung = 'penunjukan_langsung';
    case Rfq = 'rfq';
    case Tender = 'tender';

    public function label(): string
    {
        return match ($this) {
            self::PembelianLangsung => 'Pembelian langsung',
            self::PenunjukanLangsung => 'Penunjukan langsung',
            self::Rfq => 'Seleksi / banding penawaran (RFQ)',
            self::Tender => 'Tender',
        };
    }
}
