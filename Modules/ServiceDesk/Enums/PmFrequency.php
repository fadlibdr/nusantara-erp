<?php

namespace Modules\ServiceDesk\Enums;

enum PmFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Bulanan',
            self::Quarterly => 'Triwulanan',
            self::Semiannual => 'Semesteran',
        };
    }

    /**
     * Interval in months used to roll next_due_date forward.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
        };
    }
}
