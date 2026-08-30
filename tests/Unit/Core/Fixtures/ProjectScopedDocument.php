<?php

namespace Tests\Unit\Core\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

/**
 * P8 — {PROJ}: a numbered document that points at a project the way every
 * project-bound module model does — a `project` belongsTo over a project_id
 * column. The trait resolves the scope through exactly this shape.
 */
class ProjectScopedDocument extends BaseModel
{
    use HasDocumentNumber;

    public string $documentType = 'PO';

    protected $table = 'test_documents';

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
