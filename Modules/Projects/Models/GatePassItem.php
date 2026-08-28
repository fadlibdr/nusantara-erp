<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Models\Item;

/**
 * One line of the RINCIAN MATERIAL / PERALATAN table on an IMK gate pass.
 * item_id is a shared-ID reference (inv_items, no FK) — a genset on loan or a
 * borrowed scaffold set is not necessarily a stocked item, so description
 * stands on its own.
 */
class GatePassItem extends BaseModel
{
    protected $table = 'prj_gate_pass_items';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class, 'gate_pass_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
