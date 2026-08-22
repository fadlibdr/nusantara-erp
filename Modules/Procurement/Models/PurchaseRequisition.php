<?php

namespace Modules\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

class PurchaseRequisition extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_purchase_requisitions';

    public string $documentType = 'PR';

    protected function casts(): array
    {
        return [
            'needed_date' => 'date',
            'status' => DocumentStatus::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionItem::class, 'purchase_requisition_id')->orderBy('line_no');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'purchase_requisition_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The job this requisition is raised for, when it is raised for one at all
     * — an office purchase has none, which is why the column is nullable.
     *
     * Same cross-module belongsTo PurchaseOrder already carries: the FK is an
     * index without a database constraint (prj_projects may be absent on a
     * minimal install), and the relation is what lets the printed sheet name
     * the project without a query written inside a print registry row.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
