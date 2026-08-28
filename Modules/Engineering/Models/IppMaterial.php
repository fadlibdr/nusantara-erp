<?php

namespace Modules\Engineering\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Models\Item;

/** Baris bahan on an IPP (Master IPP kolom BAHAN). */
class IppMaterial extends BaseModel
{
    protected $table = 'eng_ipp_materials';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function ipp(): BelongsTo
    {
        return $this->belongsTo(WorkPermitIpp::class, 'ipp_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
