<?php

namespace Modules\Crm\Enums;

/**
 * P7 — dalam negeri / luar negeri, used for two different questions:
 * where a tool was BUILT (`made_in`) and where a general-service provider
 * comes FROM (`provider_origin`).
 *
 * Ownership is a third question with a third answer (campuran), so it has its
 * own enum — TkdnOwnership. Permenperin 35/2025 Lampiran IV huruf B angka 2
 * crosses the two, and collapsing them into one list would make the crossing
 * impossible to express.
 */
enum TkdnOrigin: string
{
    case Dn = 'dn';
    case Ln = 'ln';

    public function label(): string
    {
        return match ($this) {
            self::Dn => 'Dalam negeri',
            self::Ln => 'Luar negeri',
        };
    }
}
