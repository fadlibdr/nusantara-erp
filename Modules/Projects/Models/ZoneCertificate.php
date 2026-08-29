<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Models\Location;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Enums\CertifyingParty;
use Modules\Projects\Enums\ZoneCertificateStatus;

/**
 * BAPP per zona. HasDocumentNumber ('BAPP') but NOT Approvable — its status is
 * ZoneCertificateStatus, a record of what an inspector saw, not the stages of
 * an approval. See the migration for why several certificates per zone are
 * expected and which one counts.
 */
class ZoneCertificate extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_zone_certificates';

    public string $documentType = 'BAPP';

    protected function casts(): array
    {
        return [
            'status' => ZoneCertificateStatus::class,
            'certified_by_party' => CertifyingParty::class,
            'certified_at' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function blocksBilling(): bool
    {
        return $this->status->blocksBilling();
    }
}
