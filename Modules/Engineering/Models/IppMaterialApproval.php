<?php

namespace Modules\Engineering\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/** Baris material approval on an IPP — the reference the submit gate weighs. */
class IppMaterialApproval extends BaseModel
{
    protected $table = 'eng_ipp_material_approvals';

    public function ipp(): BelongsTo
    {
        return $this->belongsTo(WorkPermitIpp::class, 'ipp_id');
    }

    public function materialSubmittal(): BelongsTo
    {
        return $this->belongsTo(MaterialSubmittal::class, 'material_submittal_id');
    }
}
