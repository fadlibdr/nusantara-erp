<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\BankStatementDirection;
use Modules\Finance\Enums\BankStatementMatchStatus;

/**
 * One movement as the bank reported it.
 *
 * matched_type/matched_id is a manual morph in the same style as
 * PaymentAllocation: two counterpart kinds, resolved by a match() rather than
 * by Eloquent's morph map, so the pair of types stays visible in the code.
 */
class BankStatementLine extends BaseModel
{
    public const MATCH_PAYMENT = 'payment';

    public const MATCH_JOURNAL_LINE = 'journal_line';

    /**
     * Why a line has no counterpart. Classification only: it changes the
     * guidance the screen offers, never the reconciliation arithmetic. A bank
     * charge is still a difference until somebody books the journal.
     */
    public const REASONS = [
        'bank_charge' => 'Biaya/admin bank',
        'interest' => 'Bunga/jasa giro',
        'unrecorded_receipt' => 'Penerimaan belum dicatat',
        'unrecorded_payment' => 'Pengeluaran belum dicatat',
        'bank_error' => 'Kesalahan bank (menunggu koreksi)',
        'other' => 'Lainnya',
    ];

    protected $table = 'fin_bank_statement_lines';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'entry_date' => 'date',
            'value_date' => 'date',
            'direction' => BankStatementDirection::class,
            'amount' => 'decimal:2',
            'is_reversal' => 'boolean',
            'match_status' => BankStatementMatchStatus::class,
            'matched_at' => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function isMatched(): bool
    {
        return $this->match_status === BankStatementMatchStatus::Matched;
    }

    /** The signed movement in whole cents: positive into the account. */
    public function signedCents(): int
    {
        return $this->direction->sign() * (int) round((float) $this->amount * 100);
    }
}
