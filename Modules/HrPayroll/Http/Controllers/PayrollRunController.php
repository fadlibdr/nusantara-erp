<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\PayrollRunStoreRequest;
use Modules\HrPayroll\Http\Requests\PayrollRunUpdateRequest;
use Modules\HrPayroll\Http\Resources\PayrollRunResource;
use Modules\HrPayroll\Http\Resources\PayslipResource;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Services\PayrollPostingService;
use Modules\HrPayroll\Services\PayrollService;

class PayrollRunController extends ApiController
{
    public function __construct(
        private readonly PayrollService $service,
        private readonly PayrollPostingService $posting,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PayrollRun::query()
            ->withCount('payslips')
            ->when($request->filled('q'), fn ($query) => $query->where('code', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('run_type'), fn ($query) => $query->where('run_type', $request->string('run_type')))
            ->when($request->filled('period_year'), fn ($query) => $query->where('period_year', $request->integer('period_year')))
            ->orderByDesc('id');

        // period_year/period_month sempat diiklankan, tapi kolom layarnya
        // komposit 'period' — kunci tanpa kolom schema.js = tombol yang tidak
        // pernah muncul. Dicabut sampai seam column.sortKey disepakati; kolom
        // uang (bruto/potongan/netto) justru kolom nyata yang bisa diurutkan.
        return $this->listing($request, $query, PayrollRunResource::class,
            sortable: ['code', 'run_type', 'payment_date', 'total_gross', 'total_deductions', 'total_net', 'status']);
    }

    public function store(PayrollRunStoreRequest $request): JsonResponse
    {
        $run = $this->service->create($request->validated());

        return $this->created(PayrollRunResource::make($run));
    }

    public function show(PayrollRun $payrollRun): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(PayrollRunResource::make($payrollRun->load('payslips.employee', 'approvals.user')));
    }

    public function update(PayrollRunUpdateRequest $request, PayrollRun $payrollRun): JsonResponse
    {
        try {
            $payrollRun = $this->service->update($payrollRun, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PayrollRunResource::make($payrollRun));
    }

    public function destroy(PayrollRun $payrollRun): JsonResponse
    {
        try {
            $this->service->delete($payrollRun);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Payroll run deleted.');
    }

    public function calculate(PayrollRun $payrollRun): JsonResponse
    {
        try {
            $payrollRun = $this->service->calculate($payrollRun);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PayrollRunResource::make($payrollRun),
            'Payroll calculated for '.$payrollRun->payslips->count().' employees.',
        );
    }

    public function submit(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        if (! $payrollRun->isCalculated()) {
            return $this->error("Payroll run {$payrollRun->code} has no payslips yet — calculate it first.");
        }

        try {
            $payrollRun->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PayrollRunResource::make($payrollRun), 'Payroll run submitted.');
    }

    /**
     * Approving a run is what puts it in the books.
     *
     * The status change and the journal are one transaction: a run that says
     * "approved" while the ledger shows no salary expense is the state this
     * feature exists to end, and a half-applied approval would recreate it.
     */
    public function approve(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $payrollRun): void {
                $payrollRun->approve($request->user(), $request->input('note'));
                $this->posting->post($payrollRun, $request->user()?->id);
            });
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PayrollRunResource::make($payrollRun->refresh()),
            'Payroll run approved and posted to the ledger.'
        );
    }

    public function reject(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        try {
            $payrollRun->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PayrollRunResource::make($payrollRun), 'Payroll run rejected.');
    }

    public function payslips(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $query = $payrollRun->payslips()->with('employee')->orderBy('id');

        return $this->ok(PayslipResource::collection($query->paginate($request->integer('per_page', 50))));
    }
}
