<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;

class RfqItem extends BaseModel
{
    protected $table = 'prc_rfq_items';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'decimal:3',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(RfqQuote::class, 'rfq_item_id');
    }
}
