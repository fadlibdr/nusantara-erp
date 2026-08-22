<?php

namespace Modules\ServiceDesk\Enums;

enum ContractStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Expired => 'Berakhir',
            self::Terminated => 'Diputus',
        };
    }
}
