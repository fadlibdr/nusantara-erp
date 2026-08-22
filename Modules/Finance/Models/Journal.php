<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Approval;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Enums\PostingStatus;

class Journal extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'fin_journals';

    public string $documentType = 'JV';

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'status' => PostingStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** The maker of a hand-keyed JV; null on journals autoPost() minted. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The same core_approvals table every approvable document writes to, so the
     * auditor's trail for a manual JV — who keyed it, who posted it — is the
     * one trail, not a Finance-only sidecar.
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function isPosted(): bool
    {
        return $this->status === PostingStatus::Posted;
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines()->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines()->sum('credit'), 2);
    }
}
