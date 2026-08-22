<?php

namespace Modules\Assets\Enums;

enum MaintenanceType: string
{
    case ServiceRutin = 'service_rutin';
    case Perbaikan = 'perbaikan';
    case Kalibrasi = 'kalibrasi';

    public function label(): string
    {
        return match ($this) {
            self::ServiceRutin => 'Service Rutin',
            self::Perbaikan => 'Perbaikan',
            self::Kalibrasi => 'Kalibrasi',
        };
    }
}
