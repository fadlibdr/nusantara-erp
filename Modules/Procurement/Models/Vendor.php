<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Procurement\Enums\VendorClassification;
use Modules\Procurement\Enums\VendorStatus;
use Modules\Procurement\Enums\VendorType;

class Vendor extends BaseModel
{
    use SoftDeletes;

    protected $table = 'prc_vendors';

    protected function casts(): array
    {
        return [
            'is_pkp' => 'boolean',
            'is_subcontractor' => 'boolean',
            'vendor_type' => VendorType::class,
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

        /*
         * P4 — vendor_type and the deprecated is_subcontractor stay in step,
         * in ONE place, whatever door the write comes through (API, seeder,
         * test fixture, import). Rules, in priority order:
         *
         *   vendor_type written        it wins: is_subcontractor := (type ==
         *       subcontractor). The type can say four things, the boolean two.
         *   is_subcontractor written   the legacy door (SPA form, importer):
         *       TRUE is a claim the type must honour (=> subcontractor);
         *       FALSE can only demote a subcontractor (=> supplier) — it has
         *       no authority over mandor/rental, which it cannot express, so
         *       those keep their type (their boolean is false already, making
         *       a "change" to false impossible to observe as dirty anyway).
         *   neither written            a fresh row derives its type from the
         *       boolean's default (false => supplier).
         */
        static::saving(function (Vendor $vendor): void {
            if ($vendor->isDirty('vendor_type')) {
                $vendor->is_subcontractor = $vendor->vendor_type === VendorType::Subcontractor;

                return;
            }

            if ($vendor->isDirty('is_subcontractor') || $vendor->vendor_type === null) {
                if ((bool) $vendor->is_subcontractor) {
                    $vendor->vendor_type = VendorType::Subcontractor;
                } elseif ($vendor->vendor_type === null || $vendor->vendor_type === VendorType::Subcontractor) {
                    $vendor->vendor_type = VendorType::Supplier;
                }
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
