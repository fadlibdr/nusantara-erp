<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;

class BoqSection extends BaseModel
{
    protected $table = 'est_boq_sections';

    protected $casts = [
        'subtotal' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BoqItem::class, 'section_id')->orderBy('sort_order');
    }
}
