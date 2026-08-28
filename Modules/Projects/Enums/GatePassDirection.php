<?php

namespace Modules\Projects\Enums;

/**
 * Arah muatan pada Izin Masuk/Keluar Material & Peralatan (IMK).
 *
 * Dua kotak pada lembar F/IM — MASUK dan KELUAR — dan sejak P0-C komputer
 * boleh mencentang salah satunya, karena arah itu kini fakta yang DICATAT pada
 * izinnya, bukan tebakan atas muatan yang tidak pernah dilihatnya.
 */
enum GatePassDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Masuk',
            self::Out => 'Keluar',
        };
    }
}
