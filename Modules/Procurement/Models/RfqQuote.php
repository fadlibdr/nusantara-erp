<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu sel tabulasi banding: harga vendor X untuk baris Y. is_winner adalah
 * pilihan staf pengadaan per baris — bukan otomatis harga terendah, karena
 * termurah yang tidak bisa kirim bukan pemenang.
 */
class RfqQuote extends BaseModel
{
    protected $table = 'prc_rfq_quotes';

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'is_winner' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class, 'rfq_item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
