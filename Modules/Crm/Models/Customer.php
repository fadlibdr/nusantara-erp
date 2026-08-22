<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;

class Customer extends BaseModel
{
    use SoftDeletes;

    protected $table = 'crm_customers';

    protected function casts(): array
    {
        return [
            'is_pkp' => 'boolean',
            'payment_term_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (empty($customer->code)) {
                $customer->code = self::nextCode();
            }
        });
    }

    /**
     * Sequential CUST-nnnn code. Zero-padded, so string ordering == numeric ordering.
     */
    public static function nextCode(): string
    {
        $last = static::withTrashed()
            ->where('code', 'like', 'CUST-%')
            ->orderByDesc('code')
            ->value('code');

        $next = $last !== null ? ((int) substr($last, 5)) + 1 : 1;

        return 'CUST-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'customer_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'customer_id');
    }
}
