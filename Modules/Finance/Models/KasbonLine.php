<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\PettyCashCategory;

/**
 * One receipt of a kasbon settlement (pertanggungjawaban). Written once inside
 * KasbonService::settle()'s transaction and immutable after — the project cost
 * row it raises references ('kasbon_line', id), so idempotency is per line and
 * two same-category receipts on different WBS tasks stay two rows.
 */
class KasbonLine extends BaseModel
{
    protected $table = 'fin_kasbon_lines';

    protected function casts(): array
    {
        return [
            'category' => PettyCashCategory::class,
            'amount' => 'decimal:2',
        ];
    }

    public function kasbon(): BelongsTo
    {
        return $this->belongsTo(Kasbon::class, 'kasbon_id');
    }
}
