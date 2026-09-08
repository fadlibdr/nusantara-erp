<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Enums\ActivityType;

/**
 * Satu baris pekerjaan penjualan: telepon, rapat, email, kunjungan, catatan.
 *
 * Register, bukan dokumen — tanpa nomor, tanpa persetujuan (lihat migrasi
 * 000395). Yang membuatnya berarti adalah dua tanggal: `due_at` (rencana,
 * sebuah HARI) dan `done_at` (kenyataan, sebuah SAAT).
 */
class Activity extends BaseModel
{
    use SoftDeletes;

    protected $table = 'crm_activities';

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'due_at' => 'date',
            'done_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_id');
    }

    /** Belum dikerjakan. Satu definisi, dipakai turunan follow-up dan kartunya. */
    public function isOpen(): bool
    {
        return $this->done_at === null;
    }

    /**
     * Lewat tanggal — dan hanya bila ia masih terbuka DAN punya tanggal.
     * Sebuah catatan tanpa due_at tidak pernah "terlambat": ia tidak pernah
     * dijanjikan untuk hari mana pun.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->lt(($asOf ?? Carbon::now())->copy()->startOfDay());
    }

    /** @param  Builder<Activity>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /**
     * Aktivitas milik satu dokumen.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeFor(Builder $query, string $documentType, int $documentId): Builder
    {
        return $query->where('document_type', $documentType)->where('document_id', $documentId);
    }
}
