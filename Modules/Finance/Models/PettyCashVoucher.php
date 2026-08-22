<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Enums\PettyCashCategory;
use Modules\Finance\Enums\PettyCashVoucherStatus;

/**
 * A petty-cash expense voucher (bon): the drawer already handed the cash out,
 * this row is the custodian writing it into the books. Posted custodian-only —
 * the second pair of eyes reads the whole voucher pile at replenishment.
 */
class PettyCashVoucher extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'fin_petty_cash_vouchers';

    public string $documentType = 'PCV';

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'category' => PettyCashCategory::class,
            'amount' => 'decimal:2',
            'status' => PettyCashVoucherStatus::class,
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }

    /**
     * The PAY that reimbursed the drawer for this bon. Stamped when the
     * replenishment is SUBMITTED (the frozen set the approver reviews),
     * unstamped when it is rejected.
     */
    public function replenishmentPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'replenishment_payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === PettyCashVoucherStatus::Posted;
    }
}
