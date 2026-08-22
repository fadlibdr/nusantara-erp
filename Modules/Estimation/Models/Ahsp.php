<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Estimation\Enums\AhspCategory;

/**
 * AHSP — Analisa Harga Satuan Pekerjaan (unit-price analysis, SNI style).
 */
class Ahsp extends BaseModel
{
    use SoftDeletes;

    protected $table = 'est_ahsp';

    protected $casts = [
        'category' => AhspCategory::class,
        'overhead_pct' => 'decimal:4',
        'unit_price' => 'decimal:2',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(AhspComponent::class, 'ahsp_id');
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class, 'ahsp_id');
    }
}
