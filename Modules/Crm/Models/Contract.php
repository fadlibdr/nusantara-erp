<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Enums\ScopeType;
use Modules\Projects\Models\Project;

class Contract extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'crm_contracts';

    public string $documentType = 'CTR';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'ppn_amount' => 'decimal:2',
            'total_with_ppn' => 'decimal:2',
            'sign_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            // Set once by the first approved addendum waktu, mirroring
            // original_value — null means "never extended".
            'original_end_date' => 'date',
            'retention_pct' => 'decimal:4',
            'warranty_months' => 'integer',
            'status' => DocumentStatus::class,
            'scope_type' => ScopeType::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function termins(): HasMany
    {
        return $this->hasMany(ContractTermin::class, 'contract_id')->orderBy('termin_no');
    }

    /**
     * The job this contract is executed as, when it has been opened yet.
     *
     * prj_projects.contract_id is the link and Projects owns it; the relation
     * lives here so a CRM sheet (the printed ringkasan kontrak) can put the
     * PROYEK box on its letterhead without querying another module's table by
     * hand. hasOne, not hasMany: one contract is one project in this ERP — the
     * assumption ContractChangeOrderService already writes against when it
     * pushes an amended value onto prj_projects.
     */
    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'contract_id');
    }

    /**
     * Every bank guarantee and insurance policy raised against this contract,
     * oldest expiry first — the order the register is READ in, because the row
     * that lapses next is the one that costs money.
     */
    public function guarantees(): HasMany
    {
        return $this->hasMany(Guarantee::class, 'contract_id')
            ->orderBy('end_date')
            ->orderBy('number');
    }

    /**
     * Jadwal kontrak ini memuat termin retensi (pola "Retensi 5%" sebagai
     * termin dalam jadwal 100%). Kontrak semacam itu menagih retensinya LEWAT
     * termin itu, sehingga potongan retensi per invoice harus ditolak — dua
     * pola sekaligus mencatat piutang retensi dua kali (temuan #73).
     */
    public function hasRetentionTermin(): bool
    {
        return $this->termins()->where('is_retention', true)->exists();
    }

    /**
     * Rupiah amount held as retention (retensi) across the whole contract value.
     */
    public function retentionAmount(): float
    {
        return round((float) $this->value * (float) $this->retention_pct / 100, 2);
    }
}
