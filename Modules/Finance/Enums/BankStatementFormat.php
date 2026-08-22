<?php

namespace Modules\Finance\Enums;

enum BankStatementFormat: string
{
    case Mt940 = 'mt940';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Mt940 => 'MT940 (SWIFT)',
            self::Csv => 'CSV rekening koran',
        };
    }
}
