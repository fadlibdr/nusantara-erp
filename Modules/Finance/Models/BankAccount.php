<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;

class BankAccount extends BaseModel
{
    use SoftDeletes;

    protected $table = 'fin_bank_accounts';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'coa_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_account_id');
    }
}
