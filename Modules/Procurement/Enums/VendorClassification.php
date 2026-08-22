<?php

namespace Modules\Procurement\Enums;

enum VendorClassification: string
{
    case Material = 'material';
    case Jasa = 'jasa';
    case Ict = 'ict';
    case Sipil = 'sipil';
    case Me = 'me';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Jasa => 'Jasa',
            self::Ict => 'ICT',
            self::Sipil => 'Sipil',
            self::Me => 'Mekanikal & Elektrikal',
        };
    }
}
