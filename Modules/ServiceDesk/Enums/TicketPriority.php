<?php

namespace Modules\ServiceDesk\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Rendah',
            self::Medium => 'Sedang',
            self::High => 'Tinggi',
            self::Critical => 'Kritis',
        };
    }

    /**
     * Critical incidents run on the 24/7 clock; everything else on business hours.
     */
    public function usesClockHours(): bool
    {
        return $this === self::Critical;
    }
}
