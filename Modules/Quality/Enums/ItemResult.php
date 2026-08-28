<?php

namespace Modules\Quality\Enums;

/**
 * P1-QC: the verdict recorded against ONE checklist item on an inspection —
 * conform, non-conform, or not applicable. Deliberately not DocumentStatus:
 * this is a measured fact per line, not a document lifecycle. The inspection's
 * OVERALL pass/fail is derived from these (any `nok` fails the sheet —
 * InspectionService::deriveOverall); `na` never fails it, because a checklist
 * item that does not apply to this pour cannot make the pour non-conforming.
 */
enum ItemResult: string
{
    case Ok = 'ok';
    case Nok = 'nok';
    case Na = 'na';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Sesuai',
            self::Nok => 'Tidak sesuai',
            self::Na => 'Tidak berlaku',
        };
    }

    /** The one verdict that fails the whole sheet. */
    public function isNonConforming(): bool
    {
        return $this === self::Nok;
    }
}
