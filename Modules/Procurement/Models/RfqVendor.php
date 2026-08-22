<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu undangan menawar: pagar yang menentukan vendor mana saja yang boleh
 * punya sel harga pada lembar banding ini.
 */
class RfqVendor extends BaseModel
{
    protected $table = 'prc_rfq_vendors';

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
