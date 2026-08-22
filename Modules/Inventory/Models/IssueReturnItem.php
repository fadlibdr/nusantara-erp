<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class IssueReturnItem extends BaseModel
{
    protected $table = 'inv_issue_return_items';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function issueReturn(): BelongsTo
    {
        return $this->belongsTo(IssueReturn::class, 'issue_return_id');
    }

    public function issueItem(): BelongsTo
    {
        return $this->belongsTo(IssueItem::class, 'issue_item_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
