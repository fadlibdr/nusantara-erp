<?php

namespace Modules\ServiceDesk\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\ServiceDesk\Http\Requests\FieldReportAcknowledgeRequest;
use Modules\ServiceDesk\Http\Requests\FieldReportStoreRequest;
use Modules\ServiceDesk\Http\Requests\FieldReportUpdateRequest;
use Modules\ServiceDesk\Http\Resources\FieldReportResource;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Services\FieldReportService;

class FieldReportController extends ApiController
{
    public function __construct(private readonly FieldReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = FieldReport::query()
            ->with('ticket', 'technician')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('findings', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('ticket_id'), fn ($query) => $query->where('ticket_id', $request->integer('ticket_id')))
            ->when($request->filled('technician_employee_id'), fn ($query) => $query->where('technician_employee_id', $request->integer('technician_employee_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, FieldReportResource::class,
            sortable: ['code', 'report_date', 'customer_sign_name', 'status'], dateColumn: 'report_date');
    }

    public function store(FieldReportStoreRequest $request): JsonResponse
    {
        try {
            $report = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(FieldReportResource::make($report));
    }

    public function show(FieldReport $fieldReport): JsonResponse
    {
        return $this->ok(FieldReportResource::make(
            $fieldReport->load('ticket', 'technician', 'parts.item', 'warehouse', 'issue')
        ));
    }

    public function update(FieldReportUpdateRequest $request, FieldReport $fieldReport): JsonResponse
    {
        try {
            $report = $this->service->update($fieldReport, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(FieldReportResource::make($report));
    }

    public function destroy(FieldReport $fieldReport): JsonResponse
    {
        try {
            $this->service->delete($fieldReport);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Field report deleted.');
    }

    public function submit(FieldReport $fieldReport): JsonResponse
    {
        try {
            $report = $this->service->submit($fieldReport);
        } catch (DomainException|LogicException $e) {
            // DomainException is the dry run's refusal: the stock issue the
            // signature would post was tried and turned down (no gudang, closed
            // period, movement out of order), and the operator gets that reason
            // while the report is still a draft they can fix.
            return $this->error($e->getMessage());
        }

        return $this->ok(FieldReportResource::make($report), 'Field report submitted.');
    }

    /**
     * Pull a submitted report back to draft so it can be fixed.
     *
     * The endpoint the SPA needs for the only escape a submitted report has:
     * once it lists parts it blocks the close of its own month until it is
     * acknowledged, and the acknowledgement can become impossible after the
     * fact. Acknowledged reports are refused here, not routed elsewhere — the
     * bon they posted cannot be cancelled.
     */
    public function returnToDraft(FieldReport $fieldReport): JsonResponse
    {
        try {
            $report = $this->service->returnToDraft($fieldReport);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(FieldReportResource::make($report), 'Field report returned to draft.');
    }

    public function acknowledge(FieldReportAcknowledgeRequest $request, FieldReport $fieldReport): JsonResponse
    {
        try {
            $report = $this->service->acknowledge($fieldReport, (string) $request->validated('customer_sign_name'));
        } catch (DomainException|LogicException $e) {
            // DomainException carries the stock refusals — gudang not filled,
            // "Stok tidak mencukupi…" — exactly as IssueController::post does.
            return $this->error($e->getMessage());
        }

        return $this->ok(FieldReportResource::make($report), 'Field report acknowledged by customer.');
    }
}
