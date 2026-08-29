<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Subcontract\Enums\HandoverType;

/**
 * BAST subkon I/II — berita acara serah terima pekerjaan subkontraktor.
 * Shaped after Projects\Models\Bast; gated by HandoverService.
 */
class Handover extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'scm_handovers';

    public string $documentType = 'BSK';

    protected function casts(): array
    {
        return [
            'handover_type' => HandoverType::class,
            'handover_date' => 'date',
            'retention_release_due' => 'date',
            'status' => DocumentStatus::class,
        ];
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class, 'subcontract_id');
    }

    public function isFirst(): bool
    {
        return $this->handover_type === HandoverType::Bast1;
    }
}
