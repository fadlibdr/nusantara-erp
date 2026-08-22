<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Enums\KasbonStatus;

/**
 * An employee cash advance out of a petty-cash drawer. Issue books
 * Dr 1-1370 Piutang Karyawan / Cr fund — deliberately NO cost yet, because
 * nothing has been spent; cost recognition waits for the receipts at
 * settlement, which is the honest PSAK position.
 */
class Kasbon extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'fin_kasbons';

    public string $documentType = 'KSB';

    protected function casts(): array
    {
        return [
            'advance_date' => 'date',
            'due_date' => 'date',
            'settlement_date' => 'date',
            'amount' => 'decimal:2',
            'cash_returned' => 'decimal:2',
            'status' => KasbonStatus::class,
            'issued_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KasbonLine::class, 'kasbon_id');
    }

    /**
     * The PAY that reimbursed the drawer for this kasbon's settlement spend.
     * Same lifecycle as PettyCashVoucher::replenishmentPayment(): stamped at
     * replenishment SUBMIT, unstamped on reject — a settled kasbon's receipts
     * are review-set evidence exactly like a bon's.
     */
    public function replenishmentPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'replenishment_payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOutstanding(): bool
    {
        return $this->status === KasbonStatus::Issued;
    }
}
