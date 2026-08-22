<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Approval;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Enums\PaymentDirection;
use Modules\Finance\Enums\PaymentStatus;

class Payment extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'fin_payments';

    /**
     * Overwritten per direction before the number is generated: RCV for money
     * in, PAY for money out (see booting hook below).
     */
    public string $documentType = 'PAY';

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * booting() runs before bootTraits(), so this creating listener is
     * registered — and therefore fired — BEFORE HasDocumentNumber's. It picks
     * the RCV/PAY number type from the direction before the code is generated.
     */
    protected static function booting(): void
    {
        static::creating(function (Payment $payment): void {
            $direction = $payment->direction instanceof PaymentDirection
                ? $payment->direction
                : PaymentDirection::from((string) $payment->direction);

            $payment->documentType = $direction->documentType();
        });
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * Set only on a drawer top-up (PAY) or drawer return (RCV). Marks the
     * expected allocation shape: exactly one petty_cash_fund row against this
     * fund, amount pinned by the imprest rule. Ordinary payments keep it null
     * and behave bit-identically to before kas kecil existed.
     */
    public function pettyCashFund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    /**
     * Tax the customer kept back out of this receipt. It settles the invoice
     * without ever reaching the bank, so it is NOT part of $this->amount.
     */
    public function withholdings(): HasMany
    {
        return $this->hasMany(PaymentWithholding::class, 'payment_id');
    }

    /**
     * What the documents settled by this payment were credited with in total:
     * the cash plus everything withheld on the way.
     */
    public function settledTotal(): float
    {
        return round((float) $this->amount + (float) $this->withholdings()->sum('amount'), 2);
    }

    /**
     * The same core_approvals table the thirteen Approvable documents write to.
     * Payment cannot use the trait — Approvable::assertStatus casts status to
     * DocumentStatus and would fatal on a PaymentStatus — but the trail an
     * auditor reads has to be one trail, not two.
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function isPosted(): bool
    {
        return $this->status === PaymentStatus::Posted;
    }

    /**
     * Its journal has been mirrored and everything it settled has been given
     * back. Kept apart from isPosted() on purpose: a reversed payment is not a
     * draft that can be re-posted and not a document that settles anything.
     */
    public function isReversed(): bool
    {
        return $this->status === PaymentStatus::Reversed;
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isEditable(): bool
    {
        return $this->status?->isEditable() === true;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === PaymentStatus::Submitted;
    }
}
