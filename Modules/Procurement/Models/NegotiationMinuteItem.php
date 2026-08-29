<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class NegotiationMinuteItem extends BaseModel
{
    protected $table = 'prc_negotiation_minute_items';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'decimal:3',
            'harga_awal' => 'decimal:2',
            'harga_nego' => 'decimal:2',
        ];
    }

    public function minute(): BelongsTo
    {
        return $this->belongsTo(NegotiationMinute::class, 'negotiation_minute_id');
    }
}
