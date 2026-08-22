<?php

namespace Modules\Finance\Enums;

/**
 * What the operator has decided about one statement line.
 *
 * NoMatch is a review state, not an exclusion: a line the ERP has not recorded
 * is still money the bank moved, so it keeps counting as a reconciling item. It
 * only stops being a to-do. Anything else would let a clerk make a difference
 * disappear by declaring it uninteresting.
 */
enum BankStatementMatchStatus: string
{
    case Open = 'open';
    case Matched = 'matched';
    case NoMatch = 'no_match';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Belum ditinjau',
            self::Matched => 'Sudah dicocokkan',
            self::NoMatch => 'Tidak ada padanan',
        };
    }
}
