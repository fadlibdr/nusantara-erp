<?php

namespace Modules\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Projects\Models\Project;

class VendorEvaluation extends BaseModel
{
    protected $table = 'prc_vendor_evaluations';

    protected function casts(): array
    {
        return [
            'quality_score' => 'integer',
            'delivery_score' => 'integer',
            'price_score' => 'integer',
            'service_score' => 'integer',
            'total_score' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    /**
     * The job the vendor was judged on, when the evaluation names one — a
     * half-yearly evaluation of a material supplier usually does not. Index
     * without a database constraint, like every other Projects reference in
     * this module; the printed evaluation sheet reads the name through it.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
