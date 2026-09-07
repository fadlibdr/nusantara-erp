<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu akun COA yang dianggarkan pada sebuah OVB.
 *
 * Akun-akun inilah yang membuat realisasi terbaca dari jurnal tanpa satu pun
 * pemetaan yang dikarang di kode: yang dianggarkan adalah akun yang dipilih
 * pemilik, jadi realisasinya adalah mutasi akun itu juga.
 */
class OverheadBudgetLine extends BaseModel
{
    protected $table = 'fin_overhead_budget_lines';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(OverheadBudget::class, 'overhead_budget_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
