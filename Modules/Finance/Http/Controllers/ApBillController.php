<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\ApBillStoreRequest;
use Modules\Finance\Http\Requests\ApBillUpdateRequest;
use Modules\Finance\Http\Requests\DocumentCancelRequest;
use Modules\Finance\Http\Resources\ApBillResource;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Services\ApBillService;

class ApBillController extends ApiController
{
    public function __construct(private readonly ApBillService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApBill::query()
            ->with(['vendor', 'pphTax'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%")
                        ->orWhere('vendor_invoice_no', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            // Lihat ArInvoiceController::index() — alasan yang sama.
            ->when($request->boolean('unpaid'), fn ($query) => $query
                ->whereColumn('amount_paid', '<', 'total_payable')
                ->whereNot('status', DocumentStatus::Cancelled->value))
            ->orderByDesc('id');

        return $this->listing($request, $query, ApBillResource::class,
            sortable: ['code', 'bill_date', 'due_date', 'total_payable', 'status'], dateColumn: 'bill_date');
    }

    public function store(ApBillStoreRequest $request): JsonResponse
    {
        try {
            $bill = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ApBillResource::make($bill->load(['vendor', 'purchaseOrder', 'subcontractClaim', 'pphTax', 'billedReceipts.goodsReceipt'])));
    }

    public function show(ApBill $apBill): JsonResponse
    {
        return $this->ok(ApBillResource::make(
            $apBill->load(['vendor', 'purchaseOrder', 'subcontractClaim', 'pphTax', 'billedReceipts.goodsReceipt'])
        ));
    }

    public function update(ApBillUpdateRequest $request, ApBill $apBill): JsonResponse
    {
        try {
            $bill = $this->service->update($apBill, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ApBillResource::make($bill->load(['vendor', 'pphTax'])));
    }

    public function destroy(ApBill $apBill): JsonResponse
    {
        try {
            $this->service->delete($apBill);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Bill deleted.');
    }

    public function submit(Request $request, ApBill $apBill): JsonResponse
    {
        try {
            $apBill->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ApBillResource::make($apBill), 'Bill submitted.');
    }

    public function approve(Request $request, ApBill $apBill): JsonResponse
    {
        try {
            $bill = $this->service->approve($apBill, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            ApBillResource::make($bill->load(['vendor', 'purchaseOrder', 'subcontractClaim', 'pphTax', 'billedReceipts.goodsReceipt'])),
            'Bill approved and journaled.'
        );
    }

    /**
     * Membatalkan tagihan yang sudah disetujui: jurnal balik, biaya proyek
     * dilepas, PO/opname terbuka kembali untuk tagihan pengganti.
     */
    public function cancel(DocumentCancelRequest $request, ApBill $apBill): JsonResponse
    {
        try {
            $bill = $this->service->cancel($apBill, $request->user(), $request->validated('reason'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            ApBillResource::make($bill->load(['vendor', 'purchaseOrder', 'subcontractClaim', 'pphTax', 'billedReceipts.goodsReceipt'])),
            "Tagihan {$bill->code} dibatalkan; jurnal pembalik sudah diposting."
        );
    }

    public function reject(Request $request, ApBill $apBill): JsonResponse
    {
        try {
            $apBill->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ApBillResource::make($apBill), 'Bill rejected.');
    }
}
