<?php

namespace Modules\Finance\Enums;

/**
 * Lifecycle of a kasbon (KSB): draft until the custodian hands the cash over
 * (issued — the advance is in the ledger), settled by exactly ONE
 * pertanggungjawaban. A returned untouched advance is just a settlement with
 * zero lines and full cash back — there is no separate cancelled state.
 */
enum KasbonStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Settled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Issued => 'Berjalan',
            self::Settled => 'Selesai',
        };
    }
}
