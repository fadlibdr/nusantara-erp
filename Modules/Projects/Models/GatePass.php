<?php

namespace Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Enums\GatePassDirection;

/**
 * Izin Masuk/Keluar Material & Peralatan — IMK, Form F/IM (P0-C).
 *
 * Two acts in a fixed order: management approves the pass (Approvable,
 * prj.approve), then the gate CHECKS the load against it — periksa() in
 * GatePassService stamps checked_by/checked_at and refuses any pass that is
 * not yet approved. The guard checks a pass management already signed off on;
 * he does not approve one at the barrier.
 */
class GatePass extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_gate_passes';

    public string $documentType = 'IMK';

    protected function casts(): array
    {
        return [
            'direction' => GatePassDirection::class,
            'pass_date' => 'date',
            'checked_at' => 'datetime',
            'status' => DocumentStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GatePassItem::class, 'gate_pass_id');
    }

    /** The user who performed the periksa act at the gate. */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /** ASAL / TUJUAN as the sheet prints it: the registered vendor's name, else the free text. */
    public function counterpartyName(): ?string
    {
        return $this->vendor?->name ?? $this->counterparty;
    }
}
