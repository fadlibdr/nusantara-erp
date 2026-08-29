<?php

namespace Modules\Projects\Enums;

/**
 * P3 — which side of the table signed a BAPP zona.
 *
 * Same shape and same reason as Quality's WitnessParty and Engineering's
 * ReviewerParty: a RECORDED FACT about who stood in the zone, kept in the
 * module that owns the document so Projects depends on nothing it need not.
 *
 * Nullable on the row on purpose. A BAPP drafted by the site before the MK has
 * walked it has no signing party yet, and roadmap §7 is explicit that a
 * Pemilik/MK column is filled from a stored decision or left blank — never from
 * project master data "supaya terlihat lengkap".
 */
enum CertifyingParty: string
{
    case Mk = 'mk';
    case Owner = 'owner';
    case Kontraktor = 'kontraktor';

    public function label(): string
    {
        return match ($this) {
            self::Mk => 'Konsultan MK',
            self::Owner => 'Pemilik',
            self::Kontraktor => 'Kontraktor',
        };
    }
}
