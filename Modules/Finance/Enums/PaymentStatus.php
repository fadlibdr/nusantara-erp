<?php

namespace Modules\Finance\Enums;

/**
 * Lifecycle of a bank payment.
 *
 * Separate from PostingStatus even though draft and posted share their stored
 * values byte for byte, because PostingStatus also types fin_journals and
 * fin_revenue_recognition_runs and neither of those gains an approval stage.
 * Keeping the values identical is what lets fin_payments.status carry the new
 * lifecycle with NO data migration — the two rows on the live dataset are both
 * 'posted' and stay exactly as they are.
 *
 * Only outgoing payments (PAY) walk the middle three states. An incoming
 * receipt (RCV) goes draft → posted as it always did: money arriving is already
 * corroborated by a document the company does not control — the bank statement
 * the reconciliation bridge matches it against — while money leaving has no
 * such corroboration until after it has left.
 */
enum PaymentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Posted = 'posted';

    /**
     * Terminal, and deliberately NOT a return to draft: a reversed payment
     * really did move money, the bank statement will always say so, and its
     * journal stays in the ledger beside the mirror that undoes it. Every
     * query that means "this payment settled something" already filters on
     * Posted — OutstandingAsOf, PeriodCloseService's sub-ledger tie-out,
     * BankStatementMatchService's candidate pool — so a reversal drops out of
     * all of them by changing this one column, with no new filter anywhere.
     */
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Posted => 'Terposting',
            self::Reversed => 'Dibalik',
        };
    }

    /**
     * A rejected payment must be correctable — that is the whole point of
     * sending it back rather than deleting it. A submitted or approved one must
     * not be, or the approval says nothing about what gets posted.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
