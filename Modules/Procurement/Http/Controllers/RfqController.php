<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\RfqQuotesRequest;
use Modules\Procurement\Http\Requests\RfqStoreRequest;
use Modules\Procurement\Http\Requests\RfqUpdateRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Http\Resources\RfqResource;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Services\RfqService;

class RfqController extends ApiController
{
    public function __construct(private readonly RfqService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Rfq::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('notes', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, RfqResource::class,
            sortable: ['code', 'rfq_date', 'due_date', 'status'], dateColumn: 'rfq_date');
    }

    public function store(RfqStoreRequest $request): JsonResponse
    {
        try {
            $rfq = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(RfqResource::make($rfq));
    }

    public function show(Rfq $rfq): JsonResponse
    {
        return $this->ok(RfqResource::make(
            $rfq->load('items.quotes', 'vendors.vendor', 'purchaseOrders')
        ));
    }

    public function update(RfqUpdateRequest $request, Rfq $rfq): JsonResponse
    {
        try {
            $rfq = $this->service->update($rfq, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(RfqResource::make($rfq));
    }

    public function destroy(Rfq $rfq): JsonResponse
    {
        try {
            $this->service->delete($rfq);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'RFQ deleted.');
    }

    /** Staf pengadaan mengetikkan harga penawaran per vendor per baris. */
    public function quotes(RfqQuotesRequest $request, Rfq $rfq): JsonResponse
    {
        try {
            $rfq = $this->service->recordQuotes($rfq, $request->validated()['quotes']);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(RfqResource::make($rfq), 'Penawaran tercatat.');
    }

    /** Pemenang per baris (rfq_item_id terisi) atau seluruh lembar. */
    public function chooseWinner(Request $request, Rfq $rfq): JsonResponse
    {
        $vendorId = $request->integer('vendor_id');

        if ($vendorId <= 0) {
            return $this->error('vendor_id wajib diisi.');
        }

        try {
            $rfq = $this->service->chooseWinner(
                $rfq,
                $vendorId,
                $request->filled('rfq_item_id') ? $request->integer('rfq_item_id') : null,
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(RfqResource::make($rfq), 'Pemenang tercatat.');
    }

    /** "Buat PO dari RFQ" — PO draf membawa harga pemenang, tanpa diketik ulang. */
    public function createPo(Request $request, Rfq $rfq): JsonResponse
    {
        try {
            $po = $this->service->createPo($rfq, $request->only([
                'vendor_id', 'order_date', 'expected_date', 'payment_term_days',
                'discount_amount', 'delivery_address', 'notes', 'qualification_override_reason',
            ]));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(PurchaseOrderResource::make($po));
    }

    public function close(Rfq $rfq): JsonResponse
    {
        try {
            $rfq = $this->service->close($rfq);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(RfqResource::make($rfq), 'RFQ ditutup.');
    }
}
