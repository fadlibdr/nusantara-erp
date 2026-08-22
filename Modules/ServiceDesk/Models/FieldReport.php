<?php

namespace Modules\ServiceDesk\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\Warehouse;
use Modules\ServiceDesk\Enums\FieldReportStatus;

class FieldReport extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'svc_field_reports';

    // Numbered with the shared PM series (preventive maintenance visit) from config/erp.php.
    public string $documentType = 'PM';

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'customer_signed_at' => 'datetime',
            'status' => FieldReportStatus::class,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_employee_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(FieldReportPart::class, 'field_report_id');
    }

    /**
     * Gudang the visit's spare parts leave from (cross-module, Inventory).
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * The stock issue the acknowledgement posted. HasOne backed by a UNIQUE
     * column — one sign-off, one bon (see FieldReportService::acknowledge()).
     */
    public function issue(): HasOne
    {
        return $this->hasOne(Issue::class, 'field_report_id');
    }
}
