<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Enums\CostCategory;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Models\ProgressClaim;

class ApBill extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'fin_ap_bills';

    public string $documentType = 'BIL';

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'dpp' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'pph_amount' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'gl_cleared_amount' => 'decimal:2',
            'advance_applied_amount' => 'decimal:2',
            'is_advance' => 'boolean',
            'paid_at' => 'date',
            'cancelled_at' => 'datetime',
            'status' => DocumentStatus::class,
            // Null means "derive from the source document" — see
            // ApBillService::costCategory(); a stated value overrides it.
            'cost_category' => CostCategory::class,
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::Cancelled;
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function subcontractClaim(): BelongsTo
    {
        return $this->belongsTo(ProgressClaim::class, 'subcontract_claim_id');
    }

    /**
     * P4 — the mandor opname (SP3) this bill pays. A NEW column mirroring
     * subcontract_claim_id, not a reuse of it: one column pointing at two
     * tables with no discriminator is a number nobody can audit (the
     * migration adding it spells this out).
     */
    public function laborClaim(): BelongsTo
    {
        return $this->belongsTo(LaborClaim::class, 'labor_claim_id');
    }

    /**
     * The PO-less goods receipt this bill settles, if any. That receipt credited
     * the penerimaan accrual account; approving this bill debits it back out.
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function pphTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'pph_tax_id');
    }

    /**
     * Tagihan parsial: the specific posted receipts of the bill's PO that this
     * bill prices and clears. Empty for a whole-PO bill, an advance, or any
     * non-PO bill. Distinct from goodsReceipt() above, which is the PO-LESS
     * accrual route — one receipt, keyed on the bill row itself.
     */
    public function billedReceipts(): HasMany
    {
        return $this->hasMany(ApBillGoodsReceipt::class, 'ap_bill_id');
    }

    /** Bill per (PO, set of chosen GRNs) rather than for the whole order. */
    public function isPartial(): bool
    {
        return $this->billedReceipts()->exists();
    }

    /** Lihat ArInvoice::outstanding() — jurnal pembatalan sudah menghapus hutangnya. */
    public function outstanding(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        return round((float) $this->total_payable - (float) $this->amount_paid, 2);
    }

    /** Dibatalkan bukan lunas. */
    public function isFullyPaid(): bool
    {
        return ! $this->isCancelled() && $this->outstanding() <= 0.0;
    }

    /**
     * Uang muka: a down payment against a purchase order, booked to the purchase
     * advance asset account instead of an expense. It carries no goods and no
     * project cost, and the final bill for the same PO nets it off.
     */
    public function isAdvance(): bool
    {
        return (bool) $this->is_advance;
    }
}
