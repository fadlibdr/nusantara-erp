<?php

namespace Modules\Crm\Enums;

/**
 * P7 — the only thing that decides a labour cost's domestic content.
 *
 * Permenperin 35/2025 Lampiran IV huruf B angka 1: WNI = 100% KDN,
 * WNA = 0%. There is no third value and no partial credit, which is why this
 * is an enum and not a percentage column somebody could type 60 into.
 */
enum TkdnNationality: string
{
    case Wni = 'wni';
    case Wna = 'wna';

    public function label(): string
    {
        return match ($this) {
            self::Wni => 'Warga Negara Indonesia',
            self::Wna => 'Warga Negara Asing',
        };
    }

    /** Komponen dalam negeri sebagai pecahan 0..1. */
    public function domesticFactor(): float
    {
        return $this === self::Wni ? 1.0 : 0.0;
    }
}
