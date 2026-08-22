<?php

namespace Modules\HrPayroll\Enums;

enum AttendanceStatus: string
{
    case Hadir = 'hadir';
    case SetengahHari = 'setengah_hari';
    case Absen = 'absen';

    public function label(): string
    {
        return match ($this) {
            self::Hadir => 'Hadir',
            self::SetengahHari => 'Setengah Hari',
            self::Absen => 'Absen',
        };
    }
}
