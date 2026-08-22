<?php

namespace Modules\Inventory\Enums;

enum AdjustmentReason: string
{
    case Opname = 'opname';
    case Damage = 'damage';
    case Loss = 'loss';

    public function label(): string
    {
        return match ($this) {
            self::Opname => 'Stock Opname',
            self::Damage => 'Barang Rusak',
            self::Loss => 'Barang Hilang',
        };
    }
}
