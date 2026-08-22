<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Models\GoodsReceipt;

/**
 * One receipt a partial AP bill covers — see the migration for why the row is
 * a recorded fact and why goods_receipt_id is unique. No soft deletes: a row
 * either stands (its bill lives) or is deleted with the claim it records
 * (cancel/delete release the receipt for re-billing).
 */
class ApBillGoodsReceipt extends BaseModel
{
    protected $table = 'fin_ap_bill_goods_receipts';

    protected function casts(): array
    {
        return [
            'dpp_amount' => 'decimal:2',
            'cleared_amount' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(ApBill::class, 'ap_bill_id');
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }
}
