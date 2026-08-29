<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Satu periode tagihan atas satu PPK (P5). Kuantitasnya turunan (register /
 * kalender), bukan ketikan; keamanan anti-tagih-ganda ditulis di migrasi
 * 000869. Uangnya keluar lewat fin_ap_bills.work_order_billing_id.
 */
class WorkOrderBilling extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_work_order_billings';

    public string $documentType = 'PPKB';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderBillingLine::class, 'work_order_billing_id');
    }
}
