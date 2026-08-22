<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change. Append-only — see the migration for why there is no
 * update or delete path.
 */
class AuditLog extends BaseModel
{
    protected $table = 'core_audit_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
