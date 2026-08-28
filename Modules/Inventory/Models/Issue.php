<?php

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;

class Issue extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'inv_issues';

    public string $documentType = 'ISS';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'status' => StockDocumentStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * The job the material was drawn for. Null on an office or workshop bon,
     * which is exactly the case the four-party band must not invent a project
     * for. Cross-module belongsTo with no FK behind it (docs/CONVENTIONS.md §3).
     *
     * withTrashed, and the distinction it draws is the whole point: a bon with
     * NO project is an office bon, and prints as one. A bon whose project has
     * since been soft-deleted is still material drawn for that job — without
     * this the two collapse into each other, the PROYEK box empties, and the
     * house identity block (waktu pelaksanaan, hari ke, minggu ke) silently
     * disappears from a sheet that was filed as a site document. Same position
     * FormPrintService::laporanHarian takes, for the same reason. retur-material
     * inherits it through `issue.project`.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id')->withTrashed();
    }

    /** The work package the whole bon was raised against, when one was named. */
    public function wbsTask(): BelongsTo
    {
        return $this->belongsTo(WbsTask::class, 'wbs_task_id');
    }

    /**
     * The Ijin Pelaksanaan Pekerjaan this bon draws material for, when one was
     * named — the source the header wbs_task_id is inherited from
     * (IssueService). Cross-module belongsTo with no FK behind it (§3), the
     * Inventory → Engineering arrow of the site-demand chain.
     */
    public function ipp(): BelongsTo
    {
        return $this->belongsTo(WorkPermitIpp::class, 'ipp_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IssueItem::class, 'issue_id');
    }

    public function isPosted(): bool
    {
        return $this->status === StockDocumentStatus::Posted;
    }
}
