<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class DepreciationEntry extends BaseModel
{
    protected $table = 'ast_depreciation_entries';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'book_value_after' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
