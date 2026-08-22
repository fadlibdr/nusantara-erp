<?php

namespace Modules\ServiceDesk\Enums;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Bulanan',
            self::Quarterly => 'Triwulanan',
            self::Yearly => 'Tahunan',
        };
    }

    /**
     * Number of invoices per contract year for this cycle.
     */
    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Quarterly => 4,
            self::Yearly => 1,
        };
    }
}
