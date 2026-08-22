<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Projects\Models\WbsTask;

class IssueItem extends BaseModel
{
    protected $table = 'inv_issue_items';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * The work package THIS line was consumed by (temuan 13). Deliberately not
     * defaulted to the header's: one bon can serve two work packages, and a
     * line that names none names none.
     */
    public function wbsTask(): BelongsTo
    {
        return $this->belongsTo(WbsTask::class, 'wbs_task_id');
    }

    /**
     * Quantity of this line already back on the shelf through POSTED returns.
     *
     * Counted from the documents, not from a running column, so two partial
     * returns raised in parallel are both measured against the same facts when
     * postIssueReturn() re-asks inside its transaction. Drafts do not count —
     * a draft has moved nothing — and a soft-deleted return never posted.
     */
    public function qtyReturned(): float
    {
        return round((float) IssueReturnItem::query()
            ->join('inv_issue_returns as retur', 'retur.id', '=', 'inv_issue_return_items.issue_return_id')
            ->whereNull('retur.deleted_at')
            ->where('retur.status', StockDocumentStatus::Posted->value)
            ->where('inv_issue_return_items.issue_item_id', $this->id)
            ->sum('inv_issue_return_items.qty'), 3);
    }
}
