<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

/**
 * Rencana Pengadaan / Pola Belanja (PBL) — P2.
 *
 * Register perencanaan, bukan komitmen: tidak Approvable dan tidak menggerakkan
 * uang. Disusun dari RAP untuk memetakan paket belanja ke metode, target tanggal
 * kontrak, dan PIC sebelum PR terbit.
 */
class ProcurementPlan extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_procurement_plans';

    // PBL, bukan RPB: tipe RPB sudah dipakai retur pembelian (Inventory) yang
    // jatuh ke penomoran cadangan RPB/{Y}/{RM}/{N4}; memakai RPB di sini akan
    // membajak deret nomornya dan diam-diam membuang bulan romawinya.
    public string $documentType = 'PBL';

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcurementPlanItem::class, 'procurement_plan_id')->orderBy('line_no');
    }
}
