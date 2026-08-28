<?php

namespace Modules\Engineering\Enums;

/**
 * Pihak yang membubuhkan stempel keputusan pada submittal. MK pada umumnya;
 * Owner untuk paket yang pemiliknya memeriksa sendiri.
 */
enum ReviewerParty: string
{
    case Mk = 'mk';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Mk => 'Konsultan MK',
            self::Owner => 'Pemilik',
        };
    }
}
