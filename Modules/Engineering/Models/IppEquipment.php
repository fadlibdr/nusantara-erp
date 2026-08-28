<?php

namespace Modules\Engineering\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/** Baris alat on an IPP (Master IPP kolom ALAT). */
class IppEquipment extends BaseModel
{
    protected $table = 'eng_ipp_equipment';

    public function ipp(): BelongsTo
    {
        return $this->belongsTo(WorkPermitIpp::class, 'ipp_id');
    }
}
