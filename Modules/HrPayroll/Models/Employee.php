<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Enums\EmploymentType;
use Modules\HrPayroll\Enums\PkwtBasis;
use Modules\HrPayroll\Enums\PtkpStatus;

class Employee extends BaseModel
{
    use SoftDeletes;

    protected $table = 'hr_employees';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'join_date' => 'date',
            'resign_date' => 'date',
            'pkwt_basis' => PkwtBasis::class,
            'pkwt_end_date' => 'date',
            'ptkp_status' => PtkpStatus::class,
            'employment_type' => EmploymentType::class,
            'base_salary' => 'decimal:2',
            'fixed_allowances' => 'array',
        ];
    }

    public function attendanceRecaps(): HasMany
    {
        return $this->hasMany(AttendanceRecap::class, 'employee_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'employee_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function fixedAllowancesTotal(): float
    {
        return round(array_sum(array_map(
            static fn ($value): float => (float) $value,
            array_values($this->fixed_allowances ?? []),
        )), 2);
    }

    /**
     * NPWP filled, or NIK present — since NIK now functions as NPWP
     * (PMK 112/PMK.03/2022), which avoids the 120% PPh 21 surcharge.
     */
    public function hasTaxId(): bool
    {
        return filled($this->npwp) || filled($this->nik_ktp);
    }
}
