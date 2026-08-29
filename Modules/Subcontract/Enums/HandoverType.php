<?php

namespace Modules\Subcontract\Enums;

/**
 * P3 — BAST subkon I (start of masa pemeliharaan) or II (end of it).
 *
 * The same two values Projects\Enums\BastType carries, declared HERE rather
 * than imported: the owner handover and the subcontractor handover are two
 * documents with two lifecycles and two prerequisite sets, and sharing one enum
 * would be the first thread of a dependency Subcontract does not need. Values
 * kept identical so a report reading both sides does not have to translate.
 */
enum HandoverType: string
{
    case Bast1 = 'bast1';
    case Bast2 = 'bast2';

    public function label(): string
    {
        return match ($this) {
            self::Bast1 => 'BAST I (serah terima pertama)',
            self::Bast2 => 'BAST II (akhir masa pemeliharaan)',
        };
    }

    public function isFirst(): bool
    {
        return $this === self::Bast1;
    }
}
