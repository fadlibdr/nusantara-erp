<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Enums\DeploymentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\Warehouse;
use Modules\Projects\Models\Project;

class Asset extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'ast_assets';

    public string $documentType = 'AST';

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'useful_life_months' => 'integer',
            'depreciation_start_date' => 'date',
            'accumulated_depreciation' => 'decimal:2',
            'book_value' => 'decimal:2',
            'disposal_date' => 'date',
            'disposal_value' => 'decimal:2',
            'status' => AssetStatus::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function currentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'current_project_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_employee_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'asset_id');
    }

    public function activeDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class, 'asset_id')
            ->where('status', DeploymentStatus::Active->value);
    }

    /**
     * Every BBM/hour-meter reading across all this asset's deployments.
     *
     * Through the LIVE deployments only: the soft-delete scope on the
     * intermediate model excludes logs whose mobilisation somebody deleted —
     * the same stance the kartu aset takes on the two history tables it
     * already prints (a deleted mobilisation must not come back through its
     * fuel receipts).
     */
    public function equipmentLogs(): HasManyThrough
    {
        return $this->hasManyThrough(
            EquipmentLog::class,
            Deployment::class,
            'asset_id',
            'deployment_id',
        );
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class, 'asset_id');
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class, 'asset_id');
    }

    /**
     * Straight-line depreciable base: cost minus salvage, never negative.
     */
    public function depreciableBase(): float
    {
        return max(round((float) $this->acquisition_cost - (float) $this->salvage_value, 2), 0.0);
    }

    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months < 1) {
            return 0.0;
        }

        return round($this->depreciableBase() / $this->useful_life_months, 2);
    }

    public function remainingDepreciable(): float
    {
        return max(round($this->depreciableBase() - (float) $this->accumulated_depreciation, 2), 0.0);
    }

    public function isFullyDepreciated(): bool
    {
        return $this->remainingDepreciable() <= 0.0;
    }
}
