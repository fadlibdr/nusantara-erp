<?php

namespace Modules\Engineering\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Engineering\Enums\TransmittalDirection;
use Modules\Projects\Models\Project;

/**
 * Transmittal (TRM) — surat pengantar dokumen. Once received_at is stamped by
 * the tanda-terima action the sheet is signed for and locks
 * (TransmittalService refuses edits, naming the receiver).
 */
class Transmittal extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'eng_transmittals';

    public string $documentType = 'TRM';

    protected function casts(): array
    {
        return [
            'direction' => TransmittalDirection::class,
            'transmittal_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransmittalLine::class, 'transmittal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }
}
