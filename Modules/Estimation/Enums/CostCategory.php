<?php

namespace Modules\Estimation\Enums;

enum CostCategory: string
{
    case Material = 'material';
    case Labor = 'labor';
    case Subcon = 'subcon';
    case Equipment = 'equipment';
    case Overhead = 'overhead';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Labor => 'Upah',
            self::Subcon => 'Subkon',
            self::Equipment => 'Alat',
            self::Overhead => 'Overhead',
        };
    }
}
