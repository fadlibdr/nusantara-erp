<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Inventory\Enums\StockDocumentStatus;

/**
 * Retur material dari proyek — the partial mirror of one posted bon. Stock
 * comes back at the ISSUE LINE's unit cost (the price it left at), and the
 * same slice leaves project cost; see StockService::postIssueReturn().
 */
class IssueReturn extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'inv_issue_returns';

    public string $documentType = 'RTM';

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => StockDocumentStatus::class,
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IssueReturnItem::class, 'issue_return_id');
    }

    public function isPosted(): bool
    {
        return $this->status === StockDocumentStatus::Posted;
    }
}
