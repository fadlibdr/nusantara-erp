<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\CertificateStoreRequest;
use Modules\HrPayroll\Http\Requests\CertificateUpdateRequest;
use Modules\HrPayroll\Http\Resources\CertificateResource;
use Modules\HrPayroll\Models\Certificate;

class CertificateController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Certificate::query()
            ->with('employee')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('name', 'like', "%{$q}%")
                        ->orWhere('number', 'like', "%{$q}%")
                        ->orWhere('issuer', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('certificate_type'), fn ($query) => $query->where('certificate_type', $request->string('certificate_type')))
            // Nearest expiry first, never-expiring last: the row about to lapse
            // is the reason anyone opens this register.
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id');

        // expiry_date is the filterable date for the same reason it is the
        // default order: the register is read by lapse, not by entry.
        return $this->listing($request, $query, CertificateResource::class,
            sortable: ['certificate_type', 'name', 'issuer', 'issued_date', 'expiry_date'], dateColumn: 'expiry_date');
    }

    public function store(CertificateStoreRequest $request): JsonResponse
    {
        $certificate = Certificate::query()->create($request->validated());

        return $this->created(CertificateResource::make($certificate->load('employee')));
    }

    public function show(Certificate $certificate): JsonResponse
    {
        return $this->ok(CertificateResource::make($certificate->load('employee')));
    }

    public function update(CertificateUpdateRequest $request, Certificate $certificate): JsonResponse
    {
        $certificate->update($request->validated());

        return $this->ok(CertificateResource::make($certificate->load('employee')));
    }

    /**
     * Soft delete — how a dropped certificate stops the expiry reminder while
     * the row stays recoverable for the audit trail.
     */
    public function destroy(Certificate $certificate): JsonResponse
    {
        $certificate->delete();

        return $this->ok(null, 'Certificate deleted.');
    }
}
