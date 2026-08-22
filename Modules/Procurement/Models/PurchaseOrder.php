<?php

namespace Modules\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Support\Erp;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

class PurchaseOrder extends BaseModel
{
    use Approvable {
        submit as protected approvableSubmit;
    }
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_purchase_orders';

    public string $documentType = 'PO';

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'payment_term_days' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'dpp' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'ppn_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'needs_director_approval' => 'boolean',
            'status' => DocumentStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * A PO at or above the configured amount needs a director's approval.
     * The flag is stamped on submit so approvers see the required level up front.
     */
    public function submit(?User $by = null): static
    {
        $this->forceFill([
            'needs_director_approval' => (float) $this->total >= self::directorApprovalThreshold(),
        ]);

        return $this->approvableSubmit($by);
    }

    /**
     * One reader for approvals.purchase_order.threshold_two_level: submit()
     * stamps the flag against it and PoService::approve names it in the
     * refusal, so the number the approver is told can never drift from the
     * number the flag was computed with.
     */
    public static function directorApprovalThreshold(): float
    {
        return Erp::float('approvals.purchase_order.threshold_two_level', 100000000);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id')->orderBy('line_no');
    }

    public function isFullyReceived(): bool
    {
        return $this->items()->whereColumn('qty_received', '<', 'qty')->doesntExist();
    }
}
