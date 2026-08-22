<?php

namespace Modules\Finance\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Cogs = 'cogs';
    case Expense = 'expense';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Aset',
            self::Liability => 'Kewajiban',
            self::Equity => 'Ekuitas',
            self::Revenue => 'Pendapatan',
            self::Cogs => 'Beban Proyek (HPP)',
            self::Expense => 'Beban Operasional',
            self::Other => 'Pendapatan/Beban Lain',
        };
    }

    /**
     * Balance-sheet types carry their balance across periods; P&L types close
     * into retained earnings.
     */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }
}
