<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;

/**
 * One imprest drawer (kas kecil) per site/office, with its OWN postable 1-11xx
 * COA leaf — the BankAccount pattern — and ONE custodian. No HasDocumentNumber:
 * the code is user-entered master data (KK-HO, KK-GRAHA), like a bank account,
 * not a document.
 */
class PettyCashFund extends BaseModel
{
    use SoftDeletes;

    protected $table = 'fin_petty_cash_funds';

    protected function casts(): array
    {
        return [
            'float_amount' => 'decimal:2',
            'max_voucher_amount' => 'decimal:2',
            'max_kasbon_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'coa_account_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(PettyCashVoucher::class, 'fund_id');
    }

    public function kasbons(): HasMany
    {
        return $this->hasMany(Kasbon::class, 'fund_id');
    }
}
