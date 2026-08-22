<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;

class GoodsReceipt extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'inv_goods_receipts';

    public string $documentType = 'GRN';

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'status' => StockDocumentStatus::class,
            'gl_clearing_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Who delivered. Nullable on purpose: a receipt with neither vendor nor PO
     * is opening or found stock, which is why its journal credits the stock
     * variance account instead of a liability (see StockService::receiptCreditLeg).
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** The order this delivery is against, when there was one. */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    public function isPosted(): bool
    {
        return $this->status === StockDocumentStatus::Posted;
    }

    /**
     * True when posting this receipt credited a liability some later document
     * still has to clear — GR/IR for a PO receipt, the penerimaan accrual for a
     * PO-less receipt from a known vendor.
     *
     * False for everything with no clearing document: a receipt posted under
     * periodic inventory (no journal at all), a zero-value receipt, and a
     * receipt with neither PO nor vendor, whose credit goes to the stock
     * variance account and is closed where it is raised.
     */
    public function hasRecordedClearing(): bool
    {
        return $this->gl_clearing_account !== null && $this->recordedClearingAmount() > 0.0;
    }

    /**
     * What the receipt journal actually credited, in rupiah. This is the figure
     * a vendor bill may debit back out — never a value re-derived from the PO or
     * from today's accounting parameters.
     */
    public function recordedClearingAmount(): float
    {
        return round((float) $this->gl_clearing_amount, 2);
    }
}
