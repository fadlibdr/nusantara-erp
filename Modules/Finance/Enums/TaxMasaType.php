<?php

namespace Modules\Finance\Enums;

use Carbon\CarbonImmutable;
use Modules\Finance\Support\TaxDeadlines;

/**
 * The four monthly obligations the kalender pajak tracks — the same four
 * liability streams CashFlowService::projectTaxes() charges (2-1210 PPh 21,
 * 2-1220 PPh 23, 2-1230 PPh final 4(2), 2-1300/1-1600 net PPN). PPh 26 and
 * the annual SPT are deliberately absent: the company has no foreign
 * counterparties in its master data, and yearly obligations are not masas.
 */
enum TaxMasaType: string
{
    case Pph21 = 'pph21';
    case Pph23 = 'pph23';
    case PphFinal42 = 'pph_final_42';
    case Ppn = 'ppn';

    public function label(): string
    {
        return match ($this) {
            self::Pph21 => 'PPh 21',
            self::Pph23 => 'PPh 23',
            self::PphFinal42 => 'PPh Final 4(2)',
            self::Ppn => 'PPN',
        };
    }

    /** Setoran masa ini jatuh tempo — one rule set, in TaxDeadlines. */
    public function dueForMasa(int $year, int $month): CarbonImmutable
    {
        return $this === self::Ppn
            ? TaxDeadlines::ppnDueForMasa($year, $month)
            : TaxDeadlines::pphDueForMasa($year, $month);
    }
}
