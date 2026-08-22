<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\WithholdingType;

class PaymentWithholding extends BaseModel
{
    protected $table = 'fin_payment_withholdings';

    protected function casts(): array
    {
        return [
            'type' => WithholdingType::class,
            'amount' => 'decimal:2',
            'certificate_date' => 'date',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }
}
