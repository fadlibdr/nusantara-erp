<?php

namespace Modules\Projects\Enums;

enum ProjectStatus: string
{
    case Preparation = 'preparation';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Finishing = 'finishing';
    case Warranty = 'warranty';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Preparation => 'Persiapan',
            self::Active => 'Berjalan',
            self::OnHold => 'Ditangguhkan',
            self::Finishing => 'Finishing',
            self::Warranty => 'Masa Pemeliharaan',
            self::Closed => 'Ditutup',
        };
    }

    /**
     * Statuses in which site data (daily reports, progress) may still be entered.
     */
    public function isOperational(): bool
    {
        return in_array($this, [self::Preparation, self::Active, self::Finishing], true);
    }
}
