<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Procurement\Models\Vendor;

/**
 * Retur pembelian — goods going back to the vendor against ONE goods receipt.
 * Posting reverses that slice of the receipt's recorded clearing and hands the
 * quantity back to Procurement; see StockService::postPurchaseReturn().
 */
class PurchaseReturn extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'inv_purchase_returns';

    public string $documentType = 'RPB';

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => StockDocumentStatus::class,
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Who the goods go back to — copied from the receipt at creation, never
     * chosen (see PurchaseReturnService::create), so the vendor on the paper
     * is the vendor whose clearing this return reverses.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'purchase_return_id');
    }

    public function isPosted(): bool
    {
        return $this->status === StockDocumentStatus::Posted;
    }
}
