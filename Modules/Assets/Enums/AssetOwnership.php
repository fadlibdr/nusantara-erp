<?php

namespace Modules\Assets\Enums;

/**
 * P5 — kepemilikan aset. Owned dibeli dan disusutkan; rented milik vendor
 * rental (prc_vendors), tidak pernah disusutkan (gate di
 * DepreciationService::runForPeriod) dan nilai bukunya NULL — alat itu tidak
 * ada di neraca kita, jadi "Rp 0" pun sudah terlalu banyak bicara.
 */
enum AssetOwnership: string
{
    case Owned = 'owned';
    case Rented = 'rented';

    public function label(): string
    {
        return match ($this) {
            self::Owned => 'Milik sendiri',
            self::Rented => 'Sewa',
        };
    }
}
