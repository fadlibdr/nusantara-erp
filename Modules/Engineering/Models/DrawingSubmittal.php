<?php

namespace Modules\Engineering\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Engineering\Enums\ReviewerParty;
use Modules\Engineering\Enums\SubmittalDecision;

/**
 * Pengajuan persetujuan shop drawing (SDS, FM-10-03) — one revision handed to
 * the MK/Owner.
 *
 * NOT Approvable, on a written decision: the MK is external, the four FM-10
 * stamps are DATA somebody types from the returned sheet, so the decision
 * lives in recorded-fact columns and maker-checker applies to the RECORDING
 * (DrawingSubmittalService::recordDecision — the recorder may not be
 * created_by). An internal submit → approve cycle here would put our own
 * names on the MK's stamp.
 *
 * 🧪 NAMED SEAM (persetujuan eksternal): when this document joins
 * ExternalApprovableDocuments in mode TRANSISI, the adapter service maps the
 * MK's one-time-link decision onto recordDecision — four stamps, not three,
 * so ExternalDecision must grow a value or map approved_with_notes →
 * approved_as_noted. Deliberately not wired in P1-ENG lane BACKEND-1: the
 * registry row, the enum widening and the public page's wording belong to one
 * change, made together.
 */
class DrawingSubmittal extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'eng_drawing_submittals';

    public string $documentType = 'SDS';

    protected function casts(): array
    {
        return [
            'submitted_at' => 'date',
            'reviewer_party' => ReviewerParty::class,
            'decision' => SubmittalDecision::class,
            'decided_at' => 'date',
            'superseded_at' => 'datetime',
        ];
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(Drawing::class, 'drawing_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function isDecided(): bool
    {
        return $this->decision !== null;
    }
}
