<?php

namespace Modules\Crm\Enums;

enum ScopeType: string
{
    case Construction = 'construction';
    case SystemIntegration = 'system_integration';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Construction => 'Konstruksi Gedung',
            self::SystemIntegration => 'Integrasi Sistem (ELV/ICT)',
            self::Maintenance => 'Pemeliharaan',
        };
    }
}
