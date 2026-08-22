<?php

namespace Modules\Inventory\Enums;

enum ItemType: string
{
    case Material = 'material';
    case Sparepart = 'sparepart';
    case Tool = 'tool';
    case Merchandise = 'merchandise';

    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Sparepart => 'Sparepart',
            self::Tool => 'Alat Bantu',
            self::Merchandise => 'Barang Dagangan',
        };
    }
}
