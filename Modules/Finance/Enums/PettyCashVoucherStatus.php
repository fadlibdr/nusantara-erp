<?php

namespace Modules\Finance\Enums;

/**
 * Lifecycle of a petty-cash voucher (PCV): draft until the custodian posts it,
 * cancelled by reversal after. Separate from PostingStatus — which also types
 * fin_journals — because a voucher gains a cancelled state journals never have.
 */
enum PettyCashVoucherStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Posted => 'Terposting',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
