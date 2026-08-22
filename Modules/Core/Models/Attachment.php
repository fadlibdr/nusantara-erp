<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Support\AttachableDocuments;

class Attachment extends BaseModel
{
    protected $table = 'core_attachments';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'taken_at' => 'datetime',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Named `uploader`, not `uploadedBy`. The latter serialises to the key
     * "uploaded_by", which is also the foreign-key column — the relation
     * overwrites the integer, and any client reading either one gets the other.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** The slug the API and the SPA address this attachment's parent by. */
    public function documentSlug(): ?string
    {
        return AttachableDocuments::slugForClass($this->attachable_type);
    }

    /**
     * Whether a browser may render this in place rather than downloading it.
     * Images and PDFs only — anything else is served as a download so a file
     * that turns out to be markup cannot execute in the application's origin.
     */
    public function isInlineSafe(): bool
    {
        return str_starts_with($this->mime, 'image/') || $this->mime === 'application/pdf';
    }
}
