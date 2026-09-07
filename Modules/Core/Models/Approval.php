<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends BaseModel
{
    protected $table = 'core_approvals';

    protected $casts = [
        // F-1 — stempel kebijakan pada baris `submitted`. array, jadi pembaca
        // mendapat stempelnya apa adanya tanpa json_decode di enam tempat.
        'policy' => 'array',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pemberi delegasi yang haknya dipakai persetujuan ini — "Budi a.n. Sari".
     * null pada persetujuan biasa, yaitu hampir semuanya.
     */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_user_id');
    }
}
