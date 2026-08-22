<?php

namespace Modules\Finance\Enums;

/**
 * Lifecycle of ledger documents (journals, payments): draft until posted.
 */
enum PostingStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Posted => 'Terposting',
        };
    }
}
