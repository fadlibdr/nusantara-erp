<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Procurement\Enums\VendorClassification;
use Modules\Procurement\Enums\VendorStatus;

class Vendor extends BaseModel
{
    use SoftDeletes;

    protected $table = 'prc_vendors';

    protected function casts(): array
    {
        return [
            'is_pkp' => 'boolean',
            'is_subcontractor' => 'boolean',
            'classification' => VendorClassification::class,
            'payment_term_days' => 'integer',
            'rating' => 'decimal:1',
            'status' => VendorStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Vendor $vendor): void {
            if (empty($vendor->code)) {
                $vendor->code = self::nextCode();
            }
        });
    }

    /**
     * Sequential VND-nnnn code. Zero-padded, so string ordering == numeric ordering.
     */
    public static function nextCode(): string
    {
        $last = static::withTrashed()
            ->where('code', 'like', 'VND-%')
            ->orderByDesc('code')
            ->value('code');

        $next = $last !== null ? ((int) substr($last, 4)) + 1 : 1;

        return 'VND-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'vendor_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(VendorEvaluation::class, 'vendor_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class, 'vendor_id');
    }
}
