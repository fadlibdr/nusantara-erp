<?php

namespace Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Enums\Weather;

/**
 * NOTE ON `photos`: it is DEAD, and kept only because dropping a column
 * destroys whatever an installation put in it.
 *
 * It holds bare path strings and nothing ever stored a file behind them — the
 * seeded values point at paths that have never existed. Site photographs are
 * `core_attachments` rows (Modules\Core\Services\AttachmentService), which carry
 * the file itself, its GPS position and its distance from the project site. Do
 * not write to this column; a reader that trusts it shows paths to nothing.
 */
class DailyReport extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_daily_reports';

    public string $documentType = 'DRP';

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'weather_am' => Weather::class,
            'weather_pm' => Weather::class,
            'manpower_count' => 'integer',
            'photos' => 'array',
            'locked_at' => 'datetime',
            // work_start/work_end sengaja TIDAK di-cast: kolom TIME dibawa
            // sebagai string 'HH:MM' apa adanya (request memvalidasi H:i),
            // dan cast datetime akan menempelkan tanggal hari ini padanya.
        ];
    }

    /** Terkunci oleh BAST I yang disetujui (kelak juga keputusan eksternal). */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(DailyReportMaterial::class, 'daily_report_id');
    }

    /**
     * JUMLAH ORANG per jabatan — sumber turunannya manpower_count.
     *
     * orderBy id: tanpanya SQLite membaca lewat indeks unik
     * (daily_report_id, role_key) dan mengembalikan baris urut abjad
     * role_key, bukan urut entri. Urutan PAD tetap milik pencetak
     * (iterasi DailyReportRole::cases()), bukan urusan relasi ini.
     */
    public function manpower(): HasMany
    {
        return $this->hasMany(DailyReportManpower::class, 'daily_report_id')->orderBy('id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(DailyReportEquipment::class, 'daily_report_id')->orderBy('id');
    }

    /** MATERIAL MASUK (diterima/ditolak) — bukan materials(), yang PEMAKAIAN. */
    public function receipts(): HasMany
    {
        return $this->hasMany(DailyReportReceipt::class, 'daily_report_id')->orderBy('id');
    }

    /**
     * Baris URAIAN/PROGRESS/TARGET/HAMBATAN. Dinamai activityLines karena
     * `activities` sudah menjadi ATRIBUT (kolom teks rangkuman) — relasi
     * bernama sama akan kalah oleh atribut pada akses properti dan diam-diam
     * mengembalikan teks, bukan baris.
     */
    public function activityLines(): HasMany
    {
        return $this->hasMany(DailyReportActivity::class, 'daily_report_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
