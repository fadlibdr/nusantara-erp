<?php

namespace Modules\Crm\Enums;

/**
 * P7 — who owns the tool/facility whose depreciation is being counted.
 *
 * Permenperin 35/2025 Lampiran IV huruf B angka 2 needs THREE answers here,
 * not two: dalam negeri, luar negeri, and "dalam negeri + luar negeri"
 * (campuran), because that last row is the only one whose domestic factor is
 * not a constant — it is 50% × proporsi saham dalam negeri.
 */
enum TkdnOwnership: string
{
    case Dn = 'dn';
    case Ln = 'ln';
    case Campuran = 'campuran';

    public function label(): string
    {
        return match ($this) {
            self::Dn => 'Dimiliki dalam negeri',
            self::Ln => 'Dimiliki luar negeri',
            self::Campuran => 'Dimiliki dalam negeri + luar negeri',
        };
    }
}
