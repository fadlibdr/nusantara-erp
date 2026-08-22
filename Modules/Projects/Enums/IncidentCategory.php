<?php

namespace Modules\Projects\Enums;

/**
 * Jenis kejadian — what happened, as opposed to how badly it ended.
 *
 * The list is the one Indonesian construction actually reports against: falls
 * from height and being struck by falling material are the two that dominate
 * every national statistic, and the seeded near-miss ("material jatuh dari
 * lantai 5") is the second of them.
 */
enum IncidentCategory: string
{
    case FallFromHeight = 'fall_from_height';
    case StruckByObject = 'struck_by_object';
    case CaughtBetween = 'caught_between';
    case Electrical = 'electrical';
    case Fire = 'fire';
    case HeavyEquipment = 'heavy_equipment';
    case Excavation = 'excavation';
    case Chemical = 'chemical';
    case Traffic = 'traffic';
    case Environmental = 'environmental';
    case PropertyDamage = 'property_damage';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FallFromHeight => 'Jatuh dari ketinggian',
            self::StruckByObject => 'Tertimpa material',
            self::CaughtBetween => 'Terjepit / terlindas',
            self::Electrical => 'Listrik / tersengat',
            self::Fire => 'Kebakaran / ledakan',
            self::HeavyEquipment => 'Alat berat',
            self::Excavation => 'Galian / longsor',
            self::Chemical => 'Bahan kimia',
            self::Traffic => 'Lalu lintas / kendaraan',
            self::Environmental => 'Lingkungan (tumpahan, limbah)',
            self::PropertyDamage => 'Kerusakan properti',
            self::Other => 'Lainnya',
        };
    }
}
