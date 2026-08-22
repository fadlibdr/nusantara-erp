<?php

namespace Modules\ServiceDesk\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Contract as CrmContract;
use Modules\Crm\Models\Customer;
use Modules\ServiceDesk\Enums\BillingCycle;
use Modules\ServiceDesk\Enums\ContractStatus;

class ServiceContract extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'svc_contracts';

    public string $documentType = 'SVC';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'contract_value' => 'decimal:2',
            'sla_response_hours' => 'integer',
            'sla_resolution_hours' => 'integer',
            'billing_cycle' => BillingCycle::class,
            'status' => ContractStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function crmContract(): BelongsTo
    {
        return $this->belongsTo(CrmContract::class, 'contract_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(ContractSite::class, 'service_contract_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'service_contract_id');
    }

    public function preventiveSchedules(): HasMany
    {
        return $this->hasMany(PreventiveSchedule::class, 'service_contract_id');
    }

    public function isActive(): bool
    {
        return $this->status === ContractStatus::Active;
    }

    /**
     * Invoice amount per billing period (annual value spread over the cycle).
     */
    public function billingAmountPerPeriod(): float
    {
        $periods = $this->billing_cycle?->periodsPerYear() ?? 1;

        return round((float) $this->contract_value / $periods, 2);
    }
}
