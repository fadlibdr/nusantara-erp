<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Enums\LeadStatus;

/**
 * Satu perpindahan tahap prospek. Append-only: tidak pernah diubah, tidak
 * pernah dihapus — lihat migrasi 000398.
 */
class LeadStatusChange extends BaseModel
{
    protected $table = 'crm_lead_status_changes';

    /** Hanya created_at; baris ini tidak pernah berubah. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => LeadStatus::class,
            'to_status' => LeadStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
