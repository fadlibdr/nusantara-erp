<?php

namespace Modules\ServiceDesk\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class TicketActivity extends BaseModel
{
    protected $table = 'svc_ticket_activities';

    public const TYPE_COMMENT = 'comment';

    public const TYPE_STATUS_CHANGE = 'status_change';

    public const TYPE_ASSIGNMENT = 'assignment';

    public const TYPE_WORK_LOG = 'work_log';

    protected function casts(): array
    {
        return [
            'minutes_spent' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
