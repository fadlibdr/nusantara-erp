<?php

namespace Modules\Engineering\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/** Baris gambar on an IPP — the reference the submit gate weighs. */
class IppDrawing extends BaseModel
{
    protected $table = 'eng_ipp_drawings';

    public function ipp(): BelongsTo
    {
        return $this->belongsTo(WorkPermitIpp::class, 'ipp_id');
    }

    public function drawingSubmittal(): BelongsTo
    {
        return $this->belongsTo(DrawingSubmittal::class, 'drawing_submittal_id');
    }
}
