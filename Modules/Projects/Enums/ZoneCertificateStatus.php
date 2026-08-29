<?php

namespace Modules\Projects\Enums;

/**
 * P3 — the three marks a BAPP carries per zona, and the ONE of them that stops
 * money.
 *
 *   done            the zone is finished and accepted
 *   check           inspected, still being checked — the neutral resting state
 *   waiting_repair  a defect was found; the zone is waiting for its repair
 *
 * Its own enum and not DocumentStatus, for the reason NcrStatus gives: these
 * are not the stages of an approval, they are what an inspector wrote on a
 * sheet. A BAPP is never "submitted" and never "rejected".
 *
 * TWO GATES READ THIS ENUM, and only these two:
 *
 *   isDone()          an OPEN NCR at the zone refuses `done`
 *                     (ZoneCertificateService, 422 naming the NCR);
 *   blocksBilling()   an owner claim refuses to include a zone whose latest
 *                     BAPP says waiting_repair (kriteria #6, 422 naming the
 *                     zone). `check` deliberately does NOT block: half the
 *                     zones on a live floor are mid-inspection at any moment
 *                     and a gate that fires on them is a gate people learn to
 *                     route around.
 */
enum ZoneCertificateStatus: string
{
    case Done = 'done';
    case Check = 'check';
    case WaitingRepair = 'waiting_repair';

    public function label(): string
    {
        return match ($this) {
            self::Done => 'Selesai',
            self::Check => 'Diperiksa',
            self::WaitingRepair => 'Nunggu perbaikan',
        };
    }

    public function isDone(): bool
    {
        return $this === self::Done;
    }

    /** The one status an owner claim refuses to bill (kriteria #6). */
    public function blocksBilling(): bool
    {
        return $this === self::WaitingRepair;
    }
}
