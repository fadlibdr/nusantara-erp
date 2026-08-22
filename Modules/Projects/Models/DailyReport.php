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
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(DailyReportMaterial::class, 'daily_report_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
