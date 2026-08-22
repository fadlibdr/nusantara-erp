<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Crm\Enums\GuaranteeStatus;
use Modules\Crm\Http\Requests\GuaranteeStoreRequest;
use Modules\Crm\Http\Requests\GuaranteeUpdateRequest;
use Modules\Crm\Http\Resources\GuaranteeResource;
use Modules\Crm\Models\Guarantee;

/**
 * Register jaminan & asuransi. A register, not a document: no numbering, no
 * approval flow, no GL — the row exists so that end_date exists, because the
 * Rp 9,7 miliar jaminan uang muka of CTR/2026/I/0001 lived only in a termin's
 * free text and nothing could watch it lapse.
 */
class GuaranteeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $guarantees = Guarantee::query()
            ->with(['contract:id,code,title', 'quotation:id,code,title'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('guarantee_type'), fn ($q) => $q->where('guarantee_type', $request->string('guarantee_type')))
            ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('quotation_id'), fn ($q) => $q->where('quotation_id', $request->integer('quotation_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($query) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $query->where('number', 'like', $term)->orWhere('issuer', 'like', $term);
            }))
            // Soonest expiry first — "which guarantee dies next" is the
            // question this register answers.
            ->orderBy('end_date')
            ->orderByDesc('id');

        // end_date is the filterable date for the same reason it is the
        // default order: this register is read by expiry, not by entry.
        return $this->listing($request, $guarantees, GuaranteeResource::class,
            sortable: ['number', 'guarantee_type', 'value', 'end_date', 'status'], dateColumn: 'end_date');
    }

    public function store(GuaranteeStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        // An explicit null would override the column default and break the
        // watcher's "status = active" scope on the row's first day.
        $data['status'] = $data['status'] ?? GuaranteeStatus::Active->value;

        $guarantee = Guarantee::query()->create($data);

        return $this->created(
            GuaranteeResource::make($guarantee->load(['contract:id,code,title', 'quotation:id,code,title'])),
            'Jaminan dicatat.',
        );
    }

    public function show(Guarantee $guarantee): JsonResponse
    {
        return $this->ok(GuaranteeResource::make(
            $guarantee->load(['contract:id,code,title', 'quotation:id,code,title']),
        ));
    }

    public function update(GuaranteeUpdateRequest $request, Guarantee $guarantee): JsonResponse
    {
        $guarantee->update($request->validated());

        return $this->ok(GuaranteeResource::make(
            $guarantee->refresh()->load(['contract:id,code,title', 'quotation:id,code,title']),
        ));
    }

    public function destroy(Guarantee $guarantee): JsonResponse
    {
        $guarantee->delete();

        return $this->ok(null, 'Jaminan dihapus.');
    }
}
