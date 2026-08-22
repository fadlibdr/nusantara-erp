<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Enums\ItemType;

class Item extends BaseModel
{
    use SoftDeletes;

    protected $table = 'inv_items';

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'min_stock' => 'decimal:3',
            'avg_cost' => 'decimal:2',
            'last_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Item $item): void {
            if (! empty($item->code)) {
                return;
            }

            // ITM-nnnn — zero-padded codes sort lexicographically, so MAX(code)
            // is the latest one issued (fine up to ITM-9999).
            $last = static::withTrashed()
                ->where('code', 'like', 'ITM-%')
                ->max('code');

            $next = $last !== null ? ((int) substr((string) $last, 4)) + 1 : 1;

            $item->code = 'ITM-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'item_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class, 'item_id');
    }
}
