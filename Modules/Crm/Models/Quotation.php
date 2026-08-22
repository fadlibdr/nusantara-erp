<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Enums\ScopeType;

class Quotation extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'crm_quotations';

    public string $documentType = 'QTN';

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'dpp' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'ppn_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => DocumentStatus::class,
            'scope_type' => ScopeType::class,
            'revision' => 'integer',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'quotation_id')->orderBy('line_no');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class, 'quotation_id');
    }

    public function isWon(): bool
    {
        return $this->won_at !== null;
    }

    public function isLost(): bool
    {
        return $this->lost_at !== null;
    }
}
