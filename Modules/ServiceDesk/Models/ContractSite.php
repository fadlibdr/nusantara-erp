<?php

namespace Modules\ServiceDesk\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;

class ContractSite extends BaseModel
{
    protected $table = 'svc_contract_sites';

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'service_contract_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'site_id');
    }
}
