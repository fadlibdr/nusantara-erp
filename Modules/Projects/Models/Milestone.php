<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Models\ContractTermin;

class Milestone extends BaseModel
{
    protected $table = 'prj_milestones';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'achieved_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Billing termin (crm_contract_termins) unlocked when this milestone is achieved.
     */
    public function termin(): BelongsTo
    {
        return $this->belongsTo(ContractTermin::class, 'termin_id');
    }

    public function isAchieved(): bool
    {
        return $this->achieved_date !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isAchieved() && $this->due_date !== null && $this->due_date->isPast();
    }
}
