<?php

namespace Modules\Engineering\Enums;

enum TransmittalDirection: string
{
    case Keluar = 'keluar';
    case Masuk = 'masuk';

    public function label(): string
    {
        return match ($this) {
            self::Keluar => 'Keluar',
            self::Masuk => 'Masuk',
        };
    }
}
