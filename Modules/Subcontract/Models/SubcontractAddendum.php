<?php

namespace Modules\Subcontract\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Subcontract\Enums\AddendumChangeType;

/**
 * Addendum SPK — pekerjaan tambah-kurang against an approved SPK.
 *
 * value_change is SIGNED: negative is removed scope, which is as ordinary as
 * added scope. Approval adjusts scm_subcontracts.value (the klaim plafon) and
 * appends this addendum's lines to the SPK; existing lines are never touched.
 */
class SubcontractAddendum extends BaseModel
{
    use Approvable {
        submit as protected approvableSubmit;
    }
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'scm_subcontract_addenda';

    public string $documentType = 'ADS';

    protected function casts(): array
    {
        return [
            'addendum_date' => 'date',
            'value_change' => 'decimal:2',
            'change_type' => AddendumChangeType::class,
            'needs_director_approval' => 'boolean',
            'status' => DocumentStatus::class,
        ];
    }

    /**
     * The director flag rides on the POST-CHANGE value, against the same
     * threshold the SPK itself is gated on. Without this, the addendum is the
     * side door around the SPK director gate: an SPK just under Rp 200 juta
     * approved by a manager plus one addendum is a commitment above the
     * threshold that no director ever saw. An SPK already past the threshold
     * flags every addendum, deliberately — any change to a director-level
     * commitment is the director's to sign.
     */
    public function submit(?User $by = null): static
    {
        $newValue = round((float) $this->subcontract->value + (float) $this->value_change, 2);

        $this->forceFill([
            'needs_director_approval' => $newValue >= Subcontract::directorApprovalThreshold(),
        ]);

        return $this->approvableSubmit($by);
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class, 'subcontract_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubcontractAddendumItem::class, 'addendum_id')->orderBy('id');
    }

    public function isAddition(): bool
    {
        return (float) $this->value_change >= 0;
    }
}
