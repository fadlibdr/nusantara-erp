<?php

namespace Modules\Projects\Enums;

enum BastType: string
{
    case Bast1 = 'bast1';
    case Bast2 = 'bast2';

    public function label(): string
    {
        return match ($this) {
            self::Bast1 => 'BAST I - Serah Terima Pertama',
            self::Bast2 => 'BAST II - Serah Terima Kedua',
        };
    }
}
