<?php

namespace Modules\Engineering\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Models\BaseModel;

/**
 * One line of a transmittal: a morph to either submittal type, or free text
 * alone (document_* null). The morph target set is closed by
 * TransmittalService::LINE_KINDS — the wire never carries a class name.
 */
class TransmittalLine extends BaseModel
{
    protected $table = 'eng_transmittal_lines';

    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(Transmittal::class, 'transmittal_id');
    }

    public function document(): MorphTo
    {
        return $this->morphTo('document');
    }
}
