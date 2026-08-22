<?php

namespace Modules\Assets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * One site reading in the BBM & hour-meter register: what the gauge said
 * and/or how many litres went in, on one deployment, on one date, by one
 * person. See the migration for why the register allows several rows per
 * (deployment, date) and EquipmentLogService for the guards that keep the
 * meter trail honest.
 */
class EquipmentLog extends BaseModel
{
    protected $table = 'ast_equipment_logs';

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'hour_meter' => 'decimal:3',
            'fuel_liters' => 'decimal:3',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'deployment_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
