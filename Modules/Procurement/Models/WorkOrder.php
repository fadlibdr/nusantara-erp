<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

/**
 * PPK — perintah kerja alat sewa & jasa berbasis periode (P5, deviasi 3.5).
 *
 * Komitmen belanja, maka Approvable penuh (maker-checker di trait, approve
 * lewat WorkOrderService) — keputusan yang sama dengan PO/SPK. SENGAJA TANPA
 * gerbang direktur ala PO/SPK, dengan alasan SP3 (LaborContract): plafon
 * uangnya sudah dipagari qty_periods per baris, setiap rupiah keluar lewat
 * billing periode yang kuantitasnya diturunkan dari register/kalender lalu
 * tagihan AP ber-maker-checker terpisah, dan roadmap tidak memberi PPK ambang
 * nilai — menambahkan ambang yang tidak diminta berarti perilaku yang tidak
 * tertulis di mana pun. Bila pemilik kelak menetapkan ambang, jalurnya
 * mengikuti pola PO (config('erp.approvals') + DirectorApproval).
 */
class WorkOrder extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_work_orders';

    public string $documentType = 'PPK';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => DocumentStatus::class,
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'work_order_id')->orderBy('line_no');
    }

    public function billings(): HasMany
    {
        return $this->hasMany(WorkOrderBilling::class, 'work_order_id')->orderBy('billing_no');
    }
}
