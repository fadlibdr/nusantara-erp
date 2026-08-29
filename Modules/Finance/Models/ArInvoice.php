<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\Project;

class ArInvoice extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'fin_ar_invoices';

    public string $documentType = 'INV';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'dpp' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'ppn_amount' => 'decimal:2',
            'retention_withheld' => 'decimal:2',
            // P3 — the owner claim's three deductions and the advance flag.
            'is_advance' => 'boolean',
            'advance_recovery_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'date',
            'cancelled_at' => 'datetime',
            'status' => DocumentStatus::class,
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::Cancelled;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function termin(): BelongsTo
    {
        return $this->belongsTo(ContractTermin::class, 'termin_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** P3 — the owner opname this claim was assembled from, when it was. */
    public function measurement(): BelongsTo
    {
        return $this->belongsTo(ProgressMeasurement::class, 'measurement_id');
    }

    public function retentions(): HasMany
    {
        return $this->hasMany(ArRetention::class, 'source_invoice_id');
    }

    /**
     * Sisa tagihan menurut buku besar.
     *
     * Pembatalan sudah membalik piutangnya, jadi nol di sini bukan pembulatan
     * melainkan kenyataan: tanpa ini daftar invoice memasang angka penuh di
     * kolom "Sisa" tepat di sebelah lencana Dibatalkan, dan penagihan mengejar
     * uang yang tidak lagi ada di 1-1300. Pembatalan mensyaratkan amount_paid
     * nol, jadi angka mentahnya selalu SELURUH nilai invoice.
     */
    public function outstanding(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    /** Dibatalkan bukan lunas — jangan diturunkan dari outstanding() yang nol. */
    public function isFullyPaid(): bool
    {
        return ! $this->isCancelled() && $this->outstanding() <= 0.0;
    }
}
