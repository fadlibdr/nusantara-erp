<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Inventory\Enums\TransferStatus;

class Transfer extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'inv_transfers';

    public string $documentType = 'TRF';

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'received_date' => 'date',
            'status' => TransferStatus::class,
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class, 'transfer_id');
    }
}
