<?php

namespace Modules\Finance\Enums;

enum PeriodEventAction: string
{
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Closed => 'Ditutup',
            self::Reopened => 'Dibuka kembali',
        };
    }
}
