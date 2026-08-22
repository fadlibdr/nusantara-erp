<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Facades\DB;
use Modules\HrPayroll\Enums\EmploymentType;
use Modules\HrPayroll\Models\Employee;

class EmployeeService
{
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data): Employee {
            $data['code'] = $this->nextCode();
            $data['status'] = $data['status'] ?? 'active';

            return Employee::query()->create($data);
        });
    }

    public function update(Employee $employee, array $data): Employee
    {
        unset($data['code']); // employee codes are immutable

        if (($data['status'] ?? null) === 'resigned' && empty($data['resign_date'])) {
            $data['resign_date'] = now()->toDateString();
        }

        // A row leaving kontrak drops its PKWT clock AND its basis. The
        // request rules only prohibit SENDING the PKWT fields for non-kontrak
        // types; without this, converting PKWT → PKWTT would leave the old end
        // date behind as dead data waiting to mislead the next conversion back.
        $effectiveType = $data['employment_type'] ?? $employee->employment_type;
        $effectiveType = $effectiveType instanceof EmploymentType ? $effectiveType->value : $effectiveType;
        if ($effectiveType !== EmploymentType::Kontrak->value) {
            $data['pkwt_basis'] = null;
            $data['pkwt_end_date'] = null;
        }

        $employee->update($data);

        return $employee;
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }

    /**
     * EMP-0001 style codes. 'EMP' is not in the config/erp.php document registry
     * (that format family is Y/RM-based), so the code is derived here: zero-padded
     * suffixes keep lexicographic order equal to numeric order.
     */
    public function nextCode(): string
    {
        $last = Employee::withTrashed()
            ->where('code', 'like', 'EMP-%')
            ->orderByDesc('code')
            ->value('code');

        $next = $last === null ? 1 : ((int) substr((string) $last, 4)) + 1;

        return 'EMP-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
