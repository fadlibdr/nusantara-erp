<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * P7: one IBPRP row a RKK includes. Carries only the id — the hazard text and
 * its scores stay in prj_risk_register, read live. See migration 000391.
 */
class RkkIbprpLink extends BaseModel
{
    protected $table = 'crm_rkk_ibprp_links';

    protected function casts(): array
    {
        return [
            'risk_entry_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function rkk(): BelongsTo
    {
        return $this->belongsTo(RkkDocument::class, 'rkk_id');
    }
}
