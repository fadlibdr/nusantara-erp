<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Assets\Enums\MaintenanceType;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Procurement\Models\Vendor;

class Maintenance extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'ast_maintenances';

    public string $documentType = 'MTC';

    protected function casts(): array
    {
        return [
            'maintenance_date' => 'date',
            'maintenance_type' => MaintenanceType::class,
            'cost' => 'decimal:2',
            'next_due_date' => 'date',
            // F-7 — presisi yang sama persis dengan ast_equipment_logs.hour_meter,
            // kedua sisi perbandingan "pembacaan >= target".
            'next_due_hour_meter' => 'decimal:3',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
