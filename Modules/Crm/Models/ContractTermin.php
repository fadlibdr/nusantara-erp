<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Models\BaseModel;

class ContractTermin extends BaseModel
{
    protected $table = 'crm_contract_termins';

    protected function casts(): array
    {
        return [
            'termin_no' => 'integer',
            'percent' => 'decimal:4',
            'amount' => 'decimal:2',
            'is_retention' => 'boolean',
            'due_date' => 'date',
            'billed_at' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function isBilled(): bool
    {
        return $this->billed_at !== null;
    }

    /**
     * A calendar termin whose planned billing date has arrived while nobody has
     * invoiced it. This is the "jadwal" half of the billing queue — the half a
     * milestone can never cover, because a maintenance quarter comes due by the
     * calendar and by nothing else.
     */
    public function isDue(?Carbon $asOf = null): bool
    {
        if ($this->isBilled() || $this->due_date === null) {
            return false;
        }

        // copy() because Illuminate's Carbon is mutable: endOfDay() on the
        // caller's own instance would silently move their date.
        return $this->due_date->copy()->startOfDay()->lte(($asOf ?? Carbon::now())->copy()->endOfDay());
    }
}
