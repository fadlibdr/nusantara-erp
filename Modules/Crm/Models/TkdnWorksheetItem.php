<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Enums\TkdnCostGroup;
use Modules\Crm\Enums\TkdnNationality;
use Modules\Crm\Enums\TkdnOrigin;
use Modules\Crm\Enums\TkdnOwnership;

/** P7: satu baris biaya pada lembar TKDN. Lihat migrasi 000389. */
class TkdnWorksheetItem extends BaseModel
{
    protected $table = 'crm_tkdn_worksheet_items';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'cost_group' => TkdnCostGroup::class,
            'amount' => 'decimal:2',
            'nationality' => TkdnNationality::class,
            'made_in' => TkdnOrigin::class,
            'owned_by' => TkdnOwnership::class,
            'domestic_share_pct' => 'decimal:4',
            'provider_origin' => TkdnOrigin::class,
        ];
    }

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(TkdnWorksheet::class, 'worksheet_id');
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class, 'quotation_item_id');
    }
}
