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

    /**
     * Pemilik prospek — sales/estimator yang bertanggung jawab.
     *
     * Kolomnya bernama owner_user_id sejak F-3 (migrasi 000397); sebelumnya
     * `user_id`, nama yang tidak mengatakan apa-apa pada baris yang tetangganya
     * punya created_by dan assigned_to. Boleh kosong, dan yang kosong berbunyi
     * "Belum ditugaskan" di setiap layar — tidak pernah ditebak.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Riwayat tahap — append-only, terbaru di atas (F-3 / T3.5). Di sinilah
     * alasan sebuah perpindahan mundur tinggal dan dibaca.
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(LeadStatusChange::class, 'lead_id')->orderByDesc('id');
    }

    /** Aktivitas CRM yang menggantung pada prospek ini (F-3). */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'document_id')->where('document_type', 'lead');
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
