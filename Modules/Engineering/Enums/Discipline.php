<?php

namespace Modules\Engineering\Enums;

/**
 * Disiplin gambar pada register shop drawing (FM-10-01). ELV dan ICT berdiri
 * sendiri di samping MEP karena perusahaan ini juga integrator sistem — paket
 * ELV/ICT digambar dan disetujui terpisah dari mekanikal-elektrikal gedung.
 */
enum Discipline: string
{
    case Struktur = 'struktur';
    case Arsitektur = 'arsitektur';
    case Mep = 'mep';
    case Elv = 'elv';
    case Ict = 'ict';

    public function label(): string
    {
        return match ($this) {
            self::Struktur => 'Struktur',
            self::Arsitektur => 'Arsitektur',
            self::Mep => 'MEP',
            self::Elv => 'ELV',
            self::Ict => 'ICT',
        };
    }
}
