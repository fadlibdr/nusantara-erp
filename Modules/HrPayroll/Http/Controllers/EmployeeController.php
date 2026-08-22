<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\EmployeeStoreRequest;
use Modules\HrPayroll\Http\Requests\EmployeeUpdateRequest;
use Modules\HrPayroll\Http\Resources\EmployeeResource;
use Modules\HrPayroll\Http\Resources\PayslipResource;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Services\EmployeeService;
use Modules\HrPayroll\Services\LeaveService;

class EmployeeController extends ApiController
{
    public function __construct(private readonly EmployeeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('position', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('department'), fn ($query) => $query->where('department', $request->string('department')))
            ->when($request->filled('employment_type'), fn ($query) => $query->where('employment_type', $request->string('employment_type')))
            ->orderBy('code');

        // join_date bukan kolom daftar karyawan, jadi ia keluar dari whitelist
        // (kunci yang diiklankan tanpa kolom = tombol mati, temuan 10) — tapi
        // tetap menjadi jendela Dari/Sampai: "siapa bergabung kuartal ini"
        // adalah pertanyaan tanggal, bukan pertanyaan urutan.
        return $this->listing($request, $query, EmployeeResource::class,
            // Tanpa dateColumn: join_date tidak dirender di daftar Karyawan, dan
            // sepasang input 'Dari/Sampai tanggal' yang menyaring kolom tak
            // terlihat memendekkan daftar tanpa satu pun petunjuk alasannya.
            sortable: ['code', 'name', 'department', 'employment_type', 'base_salary', 'status']);
    }

    public function store(EmployeeStoreRequest $request): JsonResponse
    {
        $employee = $this->service->create($request->validated());

        return $this->created(EmployeeResource::make($employee));
    }

    public function show(Employee $employee): JsonResponse
    {
        return $this->ok(EmployeeResource::make($employee));
    }

    public function update(EmployeeUpdateRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->service->update($employee, $request->validated());

        return $this->ok(EmployeeResource::make($employee));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->service->delete($employee);

        return $this->ok(null, 'Employee deleted.');
    }

    public function payslips(Request $request, Employee $employee): JsonResponse
    {
        $query = $employee->payslips()
            ->with('payrollRun')
            ->when($request->filled('period_year'), function ($query) use ($request): void {
                $query->whereHas('payrollRun', fn ($run) => $run->where('period_year', $request->integer('period_year')));
            })
            ->orderByDesc('id');

        return $this->ok(PayslipResource::collection($query->paginate($request->integer('per_page', 20))));
    }

    /**
     * Saldo cuti tahunan — computed by LeaveService::balance from join_date
     * and the approved requests, never read from a stored column. ?as_of=
     * answers "what was the saldo when this December request starts", which
     * is a different entitlement year than today's.
     */
    public function leaveBalance(Request $request, Employee $employee): JsonResponse
    {
        $asOf = $request->filled('as_of')
            ? Carbon::parse($request->string('as_of'))
            : null;

        return $this->ok(app(LeaveService::class)->balance($employee, $asOf));
    }
}
