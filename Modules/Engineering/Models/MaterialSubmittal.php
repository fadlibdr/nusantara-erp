<?php

namespace Modules\Engineering\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Engineering\Enums\ReviewerParty;
use Modules\Engineering\Enums\SubmittalDecision;
use Modules\Inventory\Models\Item;
use Modules\Projects\Models\Project;

/**
 * Pengajuan persetujuan material (SMS, FM-10-05/22). Recorded-fact decision
 * columns, maker-checker on the recording — the DrawingSubmittal reasoning,
 * which lives on that class and in MaterialSubmittalService. No supersede
 * chain: a rejected material comes back as a NEW SMS row (see migration
 * 001320).
 *
 * 🧪 NAMED SEAM (persetujuan eksternal): same seam as DrawingSubmittal —
 * mode TRANSISI onto recordDecision, four stamps; wired as one change, not
 * here.
 */
class MaterialSubmittal extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'eng_material_submittals';

    public string $documentType = 'SMS';

    protected function casts(): array
    {
        return [
            'sample_attached' => 'boolean',
            'submitted_at' => 'date',
            'reviewer_party' => ReviewerParty::class,
            'decision' => SubmittalDecision::class,
            'decided_at' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDecided(): bool
    {
        return $this->decision !== null;
    }
}
