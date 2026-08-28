<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Enums\ChangeOrderType;

/**
 * Pekerjaan tambah-kurang against a signed contract.
 *
 * value_change is SIGNED: negative is removed scope, which is as ordinary as
 * added scope. An unsigned amount plus a direction flag would work too, and
 * every reader would have to remember to apply it. days_change (type waktu
 * only) is signed for the same reason — negative is pengurangan waktu.
 *
 * new_end_date is written by approval alone (computed from the contract's
 * then-current end_date), so on this row it means "the date this addendum
 * actually produced", never a proposal.
 */
class ContractChangeOrder extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'crm_contract_change_orders';

    public string $documentType = 'CCO';

    protected function casts(): array
    {
        return [
            'change_date' => 'date',
            'value_change' => 'decimal:2',
            'ppn_change' => 'decimal:2',
            'days_change' => 'integer',
            'new_end_date' => 'date',
            'change_type' => ChangeOrderType::class,
            'status' => DocumentStatus::class,
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /**
     * Termin penagihan yang dijadwalkan dari nilai tambah CCO ini (temuan
     * #14) — null selama belum dijadwalkan, dan stempel idempotensi yang
     * dibaca ulang scheduleTermin() di dalam transaksinya.
     */
    public function termin(): BelongsTo
    {
        return $this->belongsTo(ContractTermin::class, 'termin_id');
    }

    public function isAddition(): bool
    {
        return (float) $this->value_change >= 0;
    }

    public function isTimeAddendum(): bool
    {
        return $this->change_type === ChangeOrderType::Waktu;
    }
}
