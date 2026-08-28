<?php

namespace Modules\Core\Enums;

/**
 * P1-ENG: jenjang rincian lokasi tapak, dari yang terbesar ke terkecil.
 * Values are the wire vocabulary; labels are what prints and renders.
 */
enum LocationKind: string
{
    case Tower = 'tower';
    case Floor = 'floor';
    case Zone = 'zone';
    case Axis = 'axis';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::Tower => 'Tower',
            self::Floor => 'Lantai',
            self::Zone => 'Zona',
            self::Axis => 'As',
            self::Room => 'Ruang',
        };
    }
}
