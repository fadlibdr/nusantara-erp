<?php

namespace Modules\Finance\Enums;

/**
 * The BANK's side of a statement movement, as printed on the rekening koran.
 *
 * The general ledger sees the mirror image: money arriving in the account is a
 * credit on the statement and a DEBIT to the bank COA. glSide() is the only
 * place that inversion is written down, so there is one place to check it.
 */
enum BankStatementDirection: string
{
    case Debit = 'debit';    // money out of the account
    case Credit = 'credit';  // money into the account

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit (keluar)',
            self::Credit => 'Kredit (masuk)',
        };
    }

    /** Which side of the bank COA the matching journal line must sit on. */
    public function glSide(): string
    {
        return match ($this) {
            self::Credit => 'debit',
            self::Debit => 'credit',
        };
    }

    /** Which payment direction can settle a movement on this side. */
    public function paymentDirection(): PaymentDirection
    {
        return match ($this) {
            self::Credit => PaymentDirection::In,
            self::Debit => PaymentDirection::Out,
        };
    }

    /** +1 for money in, -1 for money out — used for the statement tie-out. */
    public function sign(): int
    {
        return $this === self::Credit ? 1 : -1;
    }
}
