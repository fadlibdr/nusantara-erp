<?php

namespace Modules\Crm\Enums;

/**
 * Only the states a human learns from the paper itself. 'expired' is
 * deliberately absent: it is derived from end_date, never stored, so a row
 * nobody updated cannot silence the deadline watcher — the Rp 9,7 miliar
 * advance-payment bond problem this register exists to prevent.
 */
enum GuaranteeStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Claimed = 'claimed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Berlaku',
            self::Released => 'Dikembalikan',
            self::Claimed => 'Dicairkan',
        };
    }

    /** Released and claimed guarantees are nobody's problem any more. */
    public function isLive(): bool
    {
        return $this === self::Active;
    }
}
