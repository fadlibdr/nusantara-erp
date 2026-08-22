<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Enums\GuaranteeType;

/**
 * A row in the register jaminan & asuransi. Deliberately NOT HasDocumentNumber:
 * the bank's number IS the identity (unique with issuer), and minting our own
 * sequence would put two numbers on one piece of paper.
 */
class Guarantee extends BaseModel
{
    use SoftDeletes;

    protected $table = 'crm_guarantees';

    protected function casts(): array
    {
        return [
            'guarantee_type' => GuaranteeType::class,
            'value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => GuaranteeStatus::class,
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    /**
     * Derived, never stored. Only an ACTIVE guarantee can be "expired" — a
     * released or claimed one is finished, not lapsed. The end day itself still
     * counts as valid (berlaku s/d), so strictly-before-today is the test.
     */
    public function isExpired(): bool
    {
        return $this->status === GuaranteeStatus::Active
            && $this->end_date !== null
            && $this->end_date->lt(Carbon::today());
    }

    /**
     * Sisa hari — whole days from $asOf to the last day of cover, NEGATIVE once
     * that day has passed. The register prints it and the deadline watcher
     * counts the same way.
     *
     * null on a guarantee that is no longer live, by the same rule isExpired()
     * applies: a bond already returned or already claimed secures nothing, and
     * "sisa 176 hari" printed against it would describe an obligation that does
     * not exist. The printed register rules that cell instead.
     *
     * Written through DateTime::diff rather than Carbon's diffInDays because
     * that method's sign and return type have changed between Carbon majors,
     * and a lapsed bond quietly reading as days REMAINING is the one direction
     * this number must never fail in.
     */
    public function daysRemaining(?Carbon $asOf = null): ?int
    {
        if (! $this->status->isLive() || $this->end_date === null) {
            return null;
        }

        $diff = ($asOf ?? Carbon::now())->copy()->startOfDay()
            ->diff($this->end_date->copy()->startOfDay());

        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }
}
