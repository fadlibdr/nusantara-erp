<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class BoqItem extends BaseModel
{
    protected $table = 'est_boq_items';

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BoqSection::class, 'section_id');
    }

    public function ahsp(): BelongsTo
    {
        return $this->belongsTo(Ahsp::class, 'ahsp_id');
    }
}
