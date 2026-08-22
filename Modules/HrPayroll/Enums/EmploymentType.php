<?php

namespace Modules\HrPayroll\Enums;

enum EmploymentType: string
{
    case Tetap = 'tetap';
    case Kontrak = 'kontrak';
    case Harian = 'harian';

    public function label(): string
    {
        return match ($this) {
            self::Tetap => 'Karyawan Tetap (PKWTT)',
            self::Kontrak => 'Karyawan Kontrak (PKWT)',
            self::Harian => 'Tenaga Harian Lepas',
        };
    }
}
