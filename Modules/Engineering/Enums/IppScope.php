<?php

namespace Modules\Engineering\Enums;

/**
 * Lingkup pekerjaan pada Master IPP (FM-10-11). Tiga nilai persis seperti
 * lembarnya; ELV/ICT mengaju di bawah 'mep' sebagaimana lembar aslinya.
 */
enum IppScope: string
{
    case Struktur = 'struktur';
    case Arsitek = 'arsitek';
    case Mep = 'mep';

    public function label(): string
    {
        return match ($this) {
            self::Struktur => 'Struktur',
            self::Arsitek => 'Arsitektur',
            self::Mep => 'MEP',
        };
    }
}
