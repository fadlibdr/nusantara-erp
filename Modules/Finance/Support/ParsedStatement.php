<?php

namespace Modules\Finance\Support;

/**
 * A statement as a parser read it, before anything is persisted.
 *
 * tiesOut() is the property the whole import rests on: the balances the bank
 * asserted must equal the balances its own movements produce. A parser that
 * drops a transaction almost always breaks it, which is why the check is an
 * exact equality in cents rather than a tolerance — there is no rounding
 * anywhere in this layer for a tolerance to absorb, so slack here would only
 * ever let a genuinely wrong file through.
 */
readonly class ParsedStatement
{
    /**
     * @param  list<ParsedStatementLine>  $lines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $currency,
        public string $periodStart,         // Y-m-d
        public string $periodEnd,           // Y-m-d
        public int $openingCents,
        public int $closingCents,
        public array $lines,
        public array $warnings = [],
        public ?string $statementRef = null,
        public ?string $statementNo = null,
        public ?string $accountIdentification = null,
    ) {}

    public function movementCents(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += $line->signedCents();
        }

        return $total;
    }

    public function tiesOut(): bool
    {
        return $this->openingCents + $this->movementCents() === $this->closingCents;
    }

    public function tieOutDifferenceCents(): int
    {
        return $this->openingCents + $this->movementCents() - $this->closingCents;
    }

    public function toPreview(): array
    {
        return [
            'currency' => $this->currency,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'opening_balance' => $this->openingCents / 100,
            'closing_balance' => $this->closingCents / 100,
            'movement' => $this->movementCents() / 100,
            'line_count' => count($this->lines),
            'ties_out' => $this->tiesOut(),
            'tie_out_difference' => $this->tieOutDifferenceCents() / 100,
            'statement_ref' => $this->statementRef,
            'statement_no' => $this->statementNo,
            'account_identification' => $this->accountIdentification,
            'warnings' => $this->warnings,
            'lines' => array_map(fn (ParsedStatementLine $line): array => $line->toRow(), $this->lines),
        ];
    }
}
