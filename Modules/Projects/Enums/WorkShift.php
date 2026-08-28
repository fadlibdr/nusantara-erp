<?php

namespace Modules\Projects\Enums;

/**
 * Shift kerja pada Izin Kerja Lapangan (IKL) — satu izin, satu shift.
 */
enum WorkShift: string
{
    case Pagi = 'pagi';
    case Siang = 'siang';
    case Malam = 'malam';

    public function label(): string
    {
        return match ($this) {
            self::Pagi => 'Pagi',
            self::Siang => 'Siang',
            self::Malam => 'Malam',
        };
    }
}
