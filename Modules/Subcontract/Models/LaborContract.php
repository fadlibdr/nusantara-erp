<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Enums\LaborPphScheme;

/**
 * SP3 Induk — SPK mandor upah borongan (P4).
 *
 * Sengaja TANPA gerbang direktur ala SPK subkon: plafon uangnya sudah
 * dipagari qty per baris (LaborClaimService), setiap rupiah keluar lewat
 * opname ber-maker-checker lalu tagihan AP yang disetujui terpisah, dan
 * roadmap tidak memberi SP3 ambang nilai. Menambahkan ambang yang tidak
 * diminta berarti perilaku yang tidak tertulis di mana pun.
 */
class LaborContract extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'scm_labor_contracts';

    public string $documentType = 'SP3';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'pph_scheme' => LaborPphScheme::class,
            'pph_rate' => 'decimal:4',
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
        return $this->hasMany(LaborContractItem::class, 'labor_contract_id')->orderBy('line_no');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(LaborClaim::class, 'labor_contract_id')->orderBy('claim_no');
    }
}
