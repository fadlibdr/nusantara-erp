<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * P7: berkas satu lelang. Bernomor (TND) karena berkas butuh identitas; TIDAK
 * Approvable — maker-checker pengajuannya hidup pada penawarannya. Lihat
 * migrasi 000386.
 */
class TenderPackage extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'crm_tender_packages';

    public string $documentType = 'TND';

    protected function casts(): array
    {
        return [
            'registered_at' => 'date',
            'submission_deadline' => 'date',
            'aanwijzing_date' => 'date',
            'checklist' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenderDocument::class, 'tender_package_id')
            ->orderBy('addendum_no')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function rkkDocuments(): HasMany
    {
        return $this->hasMany(RkkDocument::class, 'tender_package_id');
    }

    public function tkdnWorksheets(): HasMany
    {
        return $this->hasMany(TkdnWorksheet::class, 'tender_package_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Nomor addendum tertinggi yang tercatat; null bila belum ada satu pun. */
    public function lastAddendumNo(): ?int
    {
        $max = $this->documents()->max('addendum_no');

        return $max === null ? null : (int) $max;
    }
}
