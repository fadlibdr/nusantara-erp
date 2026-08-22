<?php

namespace Modules\HrPayroll\Enums;

enum LeaveType: string
{
    case Tahunan = 'tahunan';
    case Sakit = 'sakit';
    case Izin = 'izin';
    case Khusus = 'khusus';

    public function label(): string
    {
        return match ($this) {
            self::Tahunan => 'Cuti Tahunan',
            self::Sakit => 'Sakit',
            self::Izin => 'Izin',
            self::Khusus => 'Cuti Khusus',
        };
    }

    /**
     * Only cuti tahunan debits the 12-day saldo. Sakit and izin are recorded
     * but uncounted (UU 13/2003 Pasal 93: sick leave is paid and is not the
     * worker's annual leave), and cuti khusus (menikah, khitanan, kematian —
     * Pasal 93 ayat 4) is a statutory grant on top of the saldo. Counting any
     * of them here would burn holiday days for being ill.
     */
    public function countsAgainstBalance(): bool
    {
        return $this === self::Tahunan;
    }

    /**
     * Which recap column the approved days land in: sakit has its own column
     * on hr_attendance_recaps, everything else is 'cuti' (leave_days).
     */
    public function recapColumn(): string
    {
        return $this === self::Sakit ? 'sick_days' : 'leave_days';
    }
}
