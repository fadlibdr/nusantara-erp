<?php

namespace Modules\Projects\Enums;

/**
 * Where the finding came from.
 *
 * This is not decoration: a complaint the customer raises during masa
 * pemeliharaan carries contractual weight against the retensi — Rp 2.425.000.000
 * on CTR/2026/I/0001 — while an internal QC snag is the contractor's own
 * housekeeping. Losing the distinction means losing the ability to say which
 * repairs the retention is actually being held for.
 */
enum DefectSource: string
{
    case Handover = 'handover';
    case Warranty = 'warranty';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Handover => 'Serah terima (BAST I)',
            self::Warranty => 'Masa pemeliharaan',
            self::Internal => 'QC internal',
        };
    }
}
