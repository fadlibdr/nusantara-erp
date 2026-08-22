<?php

namespace Modules\Projects\Enums;

/**
 * Where a baseline's BAC (budget at completion) came from.
 *
 * The values are spelled IDENTICALLY to the eac_source strings
 * Modules\Finance\Services\RevenueRecognitionService writes onto every
 * PSAK 115 line, so a reader comparing the two screens sees the mirror at a
 * glance instead of having to work out that 'rap_unapproved' and
 * 'unapproved_rap' mean the same thing.
 *
 * There is deliberately no 'none'. Finance can fall back to PSAK 115 para 45
 * and recognise revenue at zero margin without any estimate, because its
 * question is "how much revenue may I book" and "cost, capped at price" is a
 * safe answer. EVM's question is "am I beating my budget", and there is no safe
 * answer to that without a budget: EV = physical% x BAC is simply undefined.
 * So a baseline with no BAC is refused, not recorded — see BaselineService.
 */
enum BacSource: string
{
    case RapApproved = 'rap_approved';
    case RapUnapproved = 'rap_unapproved';
    case Override = 'override';

    public function label(): string
    {
        return match ($this) {
            self::RapApproved => 'RAP disetujui',
            self::RapUnapproved => 'RAP belum disetujui',
            self::Override => 'Ditetapkan manual',
        };
    }

    /**
     * True when the BAC may still move underneath the baseline. The warning
     * this drives rides on every report derived from the baseline, exactly as
     * the POC line's eac_source flag does.
     */
    public function isProvisional(): bool
    {
        return $this !== self::RapApproved;
    }
}
