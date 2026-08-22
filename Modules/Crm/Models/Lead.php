<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Enums\LeadStatus;

class Lead extends BaseModel
{
    use SoftDeletes;

    protected $table = 'crm_leads';

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'status' => LeadStatus::class,
            'next_follow_up_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead): void {
            if (empty($lead->code)) {
                $lead->code = self::nextCode();
            }
        });
    }

    /**
     * Sequential LEAD-nnnn code. Zero-padded, so string ordering == numeric ordering.
     */
    public static function nextCode(): string
    {
        $last = static::withTrashed()
            ->where('code', 'like', 'LEAD-%')
            ->orderByDesc('code')
            ->value('code');

        $next = $last !== null ? ((int) substr($last, 5)) + 1 : 1;

        return 'LEAD-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'lead_id');
    }

    /** Diisi oleh "Jadikan pelanggan" — lead yang sudah dikonversi (temuan #58). */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
