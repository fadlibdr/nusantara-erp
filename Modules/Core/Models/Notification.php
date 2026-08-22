<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends BaseModel
{
    public const SUBMITTED = 'document.submitted';

    public const APPROVED = 'document.approved';

    public const REJECTED = 'document.rejected';

    /** Operational alarm from the system itself, tied to no document. */
    public const SYSTEM = 'system.alert';

    protected $table = 'core_notifications';

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
