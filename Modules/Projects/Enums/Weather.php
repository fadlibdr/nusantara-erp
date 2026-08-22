<?php

namespace Modules\Projects\Enums;

enum Weather: string
{
    case Cerah = 'cerah';
    case Mendung = 'mendung';
    case Hujan = 'hujan';

    public function label(): string
    {
        return match ($this) {
            self::Cerah => 'Cerah',
            self::Mendung => 'Mendung',
            self::Hujan => 'Hujan',
        };
    }
}
