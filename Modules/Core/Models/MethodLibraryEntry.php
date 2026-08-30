<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * P7: one VERSION of one metode pelaksanaan. See migration 000191.
 *
 * No relation to any module's model — Core may depend on none.
 */
class MethodLibraryEntry extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'core_method_library';

    public string $documentType = 'MTD';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_date' => 'date',
        ];
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function supersedes(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_id');
    }

    /** Berlaku = belum digantikan versi berikutnya. */
    public function isCurrent(): bool
    {
        return $this->superseded_by_id === null;
    }
}
