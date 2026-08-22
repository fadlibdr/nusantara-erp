<?php

namespace Modules\ServiceDesk\Enums;

enum TicketCategory: string
{
    case Incident = 'incident';
    case Request = 'request';
    case Preventive = 'preventive';

    public function label(): string
    {
        return match ($this) {
            self::Incident => 'Gangguan',
            self::Request => 'Permintaan',
            self::Preventive => 'Pemeliharaan Preventif',
        };
    }
}
