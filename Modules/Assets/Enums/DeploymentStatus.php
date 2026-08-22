<?php

namespace Modules\Assets\Enums;

enum DeploymentStatus: string
{
    case Active = 'active';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Returned => 'Dikembalikan',
        };
    }
}
