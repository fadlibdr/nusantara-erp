<?php

namespace Modules\HrPayroll\Enums;

enum PayrollRunType: string
{
    case Regular = 'regular';
    case Thr = 'thr';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Gaji Bulanan',
            self::Thr => 'THR Keagamaan',
        };
    }
}
