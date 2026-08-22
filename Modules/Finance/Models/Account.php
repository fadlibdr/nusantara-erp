<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\NormalBalance;

class Account extends BaseModel
{
    use SoftDeletes;

    protected $table = 'fin_accounts';

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    /**
     * Signed balance following the account's normal side:
     * debit-normal => debit - credit, credit-normal => credit - debit.
     */
    public function signedBalance(float $debit, float $credit): float
    {
        return $this->normal_balance === NormalBalance::Debit
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }
}
