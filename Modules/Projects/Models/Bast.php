<?php

namespace Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Enums\BastType;

/**
 * Berita Acara Serah Terima — BAST I (first handover) / BAST II (end of warranty).
 */
class Bast extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_bast';

    public string $documentType = 'BAST';

    protected function casts(): array
    {
        return [
            'bast_type' => BastType::class,
            'handover_date' => 'date',
            'retention_release_due' => 'date',
            'status' => DocumentStatus::class,
            // What the prerequisite checklist said at the instant this BAST II
            // was approved. Written on every BAST II approval, clean or not.
            'prerequisite_snapshot' => 'array',
            'prerequisite_override_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prerequisite_override_by');
    }

    /**
     * The one that carries the money: approving a BAST II closes the project and
     * publishes the date the customer's retensi becomes collectible.
     */
    public function isBast2(): bool
    {
        return $this->bast_type === BastType::Bast2;
    }
}
