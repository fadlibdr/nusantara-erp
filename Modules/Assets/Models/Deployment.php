<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    public function isActive(): bool
    {
        return $this->status === DeploymentStatus::Active;
    }
}
