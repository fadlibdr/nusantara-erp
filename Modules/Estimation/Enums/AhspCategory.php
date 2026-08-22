<?php

namespace Modules\Estimation\Enums;

enum AhspCategory: string
{
    case Sipil = 'sipil';
    case Arsitektur = 'arsitektur';
    case Mep = 'mep';
    case Elv = 'elv';
    case Ict = 'ict';

    public function label(): string
    {
        return match ($this) {
            self::Sipil => 'Sipil',
            self::Arsitektur => 'Arsitektur',
            self::Mep => 'MEP',
            self::Elv => 'ELV',
            self::Ict => 'ICT',
        };
    }
}
