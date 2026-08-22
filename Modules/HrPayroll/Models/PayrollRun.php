<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Enums\PayrollRunType;

class PayrollRun extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'hr_payroll_runs';

    public string $documentType = 'PYR';

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'run_type' => PayrollRunType::class,
            'payment_date' => 'date',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'status' => DocumentStatus::class,
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id')->orderBy('id');
    }

    public function isCalculated(): bool
    {
        return $this->payslips()->exists();
    }
}
