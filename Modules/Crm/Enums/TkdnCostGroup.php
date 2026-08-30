<?php

namespace Modules\Crm\Enums;

/**
 * P7 — the three cost groups a TKDN Jasa worksheet is built from.
 *
 * Not a house invention: Peraturan Menteri Perindustrian No. 35 Tahun 2025
 * Pasal 14 ayat (3) names exactly these three and no others —
 *   a. tenaga kerja;
 *   b. alat kerja/fasilitas kerja; dan
 *   c. Jasa umum.
 * A fourth case here would be a cost the certifier's form has nowhere to put.
 */
enum TkdnCostGroup: string
{
    case TenagaKerja = 'tenaga_kerja';
    case AlatKerja = 'alat_kerja';
    case JasaUmum = 'jasa_umum';

    public function label(): string
    {
        return match ($this) {
            self::TenagaKerja => 'Tenaga kerja',
            self::AlatKerja => 'Alat kerja / fasilitas kerja',
            self::JasaUmum => 'Jasa umum',
        };
    }
}
