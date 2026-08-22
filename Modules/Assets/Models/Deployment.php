<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Assets\Enums\DeploymentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

class Deployment extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'ast_deployments';

    public string $documentType = 'DEP';

    protected function casts(): array
    {
        return [
            'deployed_from' => 'date',
            'planned_until' => 'date',
            'returned_at' => 'date',
            'daily_rate_internal' => 'decimal:2',
            'status' => DeploymentStatus::class,
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function equipmentLogs(): HasMany
    {
        return $this->hasMany(EquipmentLog::class, 'deployment_id');
    }

    public function isActive(): bool
    {
        return $this->status === DeploymentStatus::Active;
    }

    /**
     * Was the machine on site on this date? This — not today's status — is
     * what the BBM register asks: a returned deployment still accepts late
     * paperwork dated within its span, and an active one accepts nothing
     * dated before the machine arrived.
     */
    public function wasOnSiteOn(Carbon $date): bool
    {
        if ($date->lt($this->deployed_from)) {
            return false;
        }

        return $this->returned_at === null || ! $date->gt($this->returned_at);
    }
}
