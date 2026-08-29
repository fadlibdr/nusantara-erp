<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Estimation\Models\BoqItem;

/**
 * The volume an approved CCO adds to (or removes from) one BOQ item — the half
 * of the opname ceiling `crm_contract_change_orders` cannot express, because a
 * change order there is a signed VALUE and carries no lines at all.
 *
 * See the migration for the full argument, including why this lives in Projects.
 */
class ContractVariation extends BaseModel
{
    protected $table = 'prj_contract_variations';

    protected function casts(): array
    {
        return [
            'qty_change' => 'decimal:3',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ContractChangeOrder::class, 'change_order_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }
}
