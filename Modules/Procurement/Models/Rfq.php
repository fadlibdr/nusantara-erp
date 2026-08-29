<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

/**
 * RFQ — lembar banding penawaran vendor (temuan #34 tahap 3).
 *
 * SENGAJA TANPA Approvable: RFQ bukan komitmen dan tidak menggerakkan uang —
 * ia lembar kerja banding harga. Komitmennya adalah PO yang lahir darinya,
 * dan PO itulah yang melewati seluruh gerbang (prakualifikasi vendor, kendali
 * harga #34, gate anggaran #33, direktur, maker-checker). Status hanya dua
 * yang terpakai: draft (tabulasi masih diisi) dan closed (arsip).
 */
class Rfq extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_rfqs';

    public string $documentType = 'RFQ';

    protected function casts(): array
    {
        return [
            'rfq_date' => 'date',
            'due_date' => 'date',
            'status' => DocumentStatus::class,
        ];
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * Proyek yang dibandingkan harganya, bila lembar ini terikat proyek —
     * indeks lintas modul tanpa constraint DB, sama seperti PurchaseOrder.
     * Dipakai lembar cetak untuk menamai proyek tanpa query di dalam registri.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class, 'rfq_id')->orderBy('line_no');
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(RfqVendor::class, 'rfq_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'rfq_id');
    }

    /** P2 — tabulasi penilaian berbobot per vendor (sistem nilai DAN 4.8). */
    public function bidEvaluations(): HasMany
    {
        return $this->hasMany(BidEvaluation::class, 'rfq_id')->orderBy('rank');
    }

    public function negotiationMinutes(): HasMany
    {
        return $this->hasMany(NegotiationMinute::class, 'rfq_id');
    }

    public function awardDecisions(): HasMany
    {
        return $this->hasMany(AwardDecision::class, 'rfq_id');
    }
}
