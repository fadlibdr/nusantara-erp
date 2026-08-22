<?php

namespace Modules\Assets\Enums;

enum AssetStatus: string
{
    case Available = 'available';
    case Deployed = 'deployed';
    case Maintenance = 'maintenance';
    case Disposed = 'disposed';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::Deployed => 'Termobilisasi',
            self::Maintenance => 'Dalam Perawatan',
            self::Disposed => 'Dihapusbukukan',
        };
    }
}
