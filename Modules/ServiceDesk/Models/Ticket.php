<?php

namespace Modules\ServiceDesk\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\ServiceDesk\Enums\TicketCategory;
use Modules\ServiceDesk\Enums\TicketPriority;
use Modules\ServiceDesk\Enums\TicketStatus;

class Ticket extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'svc_tickets';

    public string $documentType = 'TKT';

    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'reported_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function serviceContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'service_contract_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ContractSite::class, 'site_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    public function fieldReports(): HasMany
    {
        return $this->hasMany(FieldReport::class, 'ticket_id');
    }

    /**
     * First response missed, or not given yet and past due.
     */
    public function responseBreached(): bool
    {
        if (! $this->response_due_at) {
            return false;
        }

        $respondedAt = $this->first_response_at;

        return $respondedAt
            ? $respondedAt->gt($this->response_due_at)
            : now()->gt($this->response_due_at);
    }

    /**
     * Resolution missed, or still unresolved past due.
     */
    public function resolutionBreached(): bool
    {
        if (! $this->resolution_due_at) {
            return false;
        }

        $resolvedAt = $this->resolved_at;

        return $resolvedAt
            ? $resolvedAt->gt($this->resolution_due_at)
            : now()->gt($this->resolution_due_at);
    }
}
