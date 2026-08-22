<?php

namespace Modules\Finance\Support;

use Modules\Finance\Enums\BankStatementDirection;

/**
 * One movement as a parser read it, before anything is persisted.
 *
 * Amounts are whole cents for the whole parsing layer. Nothing here is a float:
 * the statement tie-out is an equality, and an equality between floats that
 * were each built by multiplying a decimal string by 100 is a coin toss at the
 * edges. Cents in, cents compared, decimal written once at the database.
 */
readonly class ParsedStatementLine
{
    public function __construct(
        public int $lineNo,
        public string $entryDate,           // Y-m-d
        public ?string $valueDate,          // Y-m-d
        public BankStatementDirection $direction,
        public int $amountCents,            // always positive
        public ?string $description = null,
        public ?string $customerReference = null,
        public ?string $bankReference = null,
        public ?string $transactionCode = null,
        public bool $isReversal = false,
        public ?string $rawLine = null,
    ) {}

    /** Positive into the account, negative out. */
    public function signedCents(): int
    {
        return $this->direction->sign() * $this->amountCents;
    }

    public function toRow(): array
    {
        return [
            'line_no' => $this->lineNo,
            'entry_date' => $this->entryDate,
            'value_date' => $this->valueDate,
            'direction' => $this->direction->value,
            'amount' => $this->amountCents / 100,
            'description' => $this->description,
            'customer_reference' => $this->customerReference,
            'bank_reference' => $this->bankReference,
            'transaction_code' => $this->transactionCode,
            'is_reversal' => $this->isReversal,
            'raw_line' => $this->rawLine,
        ];
    }
}
