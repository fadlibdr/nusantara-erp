<?php

namespace Modules\Quality\Enums;

/**
 * P1-QC: who witnessed the inspection alongside the contractor's own QC. The
 * Konsultan MK on most packages; the Owner where the client attends in person.
 *
 * A RECORDED FACT, not the approver's identity: the inspection is Approvable and
 * its internal maker-checker (submit → approve, qc.approve) is the house cycle,
 * exactly as the IPP is. The witness is who stood on the slab, printed on F/QI
 * beside the contractor and MK signature columns — the same shape ReviewerParty
 * carries on an Engineering submittal, kept in this module so Quality depends on
 * nothing it need not.
 */
enum WitnessParty: string
{
    case Mk = 'mk';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Mk => 'Konsultan MK',
            self::Owner => 'Pemilik',
        };
    }
}
