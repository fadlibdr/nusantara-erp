<?php

namespace Modules\Projects\Enums;

/**
 * Where an incident is in its follow-up.
 *
 * Deliberately NOT DocumentStatus. An incident is not approved into existence —
 * it happened. What it needs is investigation and a corrective action that
 * somebody closes out, which is a different lifecycle with a different meaning
 * for every state.
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Investigating => 'Investigasi',
            self::Closed => 'Selesai',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}
