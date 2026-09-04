<?php

namespace Modules\Estimation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Estimation\Http\Requests\BoqStoreRequest;
use Modules\Estimation\Http\Requests\BoqUpdateRequest;
use Modules\Estimation\Http\Resources\BoqItemResource;
use Modules\Estimation\Http\Resources\BoqResource;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Services\BoqService;

class BoqController extends ApiController
{
    public function __construct(private readonly BoqService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Boq::query()
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($w) use ($term): void {
                    $w->where('code', 'like', $term)->orWhere('title', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->integer('contract_id')))
            ->latest('id');

        return $this->listing($request, $query, BoqResource::class,
            sortable: ['code', 'title', 'version', 'total', 'status']);
    }

    public function store(BoqStoreRequest $request): JsonResponse
    {
        $boq = $this->service->create($request->validated());

        return $this->created(new BoqResource($boq->load('sections.items')));
    }

    public function show(Boq $boq): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(new BoqResource($boq->load('sections.items', 'approvals.user')));
    }

    /**
     * A `sections` key replaces every section and item wholesale, so it gets the
     * same dependency refusal the importer gets.
     *
     * est_cost_budget_items.boq_item_id is constrained->cascadeOnDelete and
     * prj_wbs_tasks / prc_purchase_requisition_items / scm_subcontract_items
     * carry the same id with no constraint at all — so replacing the sections
     * deletes the lines of every RAP built from this BOQ and silently dangles
     * everything else. The document importer has refused exactly that since it
     * shipped; leaving this door open made the FORM the looser of the two, and
     * "the importer refuses what the form allows" is not a rule anybody can be
     * taught.
     *
     * Gated on the key, not on the request: the BOQ form sends only the header
     * fields (bagian and item are managed from the detail screen), so renaming a
     * BOQ that a RAP was built from stays possible — nothing is being replaced.
     */
    public function update(BoqUpdateRequest $request, Boq $boq): JsonResponse
    {
        $data = $request->validated();

        // Status first, and only then dependencies: an approved BOQ has always
        // refused with "cannot be edited while status is …" and must go on
        // saying that rather than complaining about a RAP it was never going to
        // reach.
        if (array_key_exists('sections', $data) && $boq->status->isEditable()) {
            $blockers = $this->service->dependencyBlockers($boq);

            if ($blockers !== []) {
                return $this->error(
                    "Rincian BOQ {$boq->code} tidak dapat diganti: ".implode(' ', $blockers),
                    422,
                    ['sections' => $blockers],
                );
            }
        }

        try {
            $boq = $this->service->update($boq, $data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new BoqResource($boq->load('sections.items')));
    }

    public function destroy(Boq $boq): JsonResponse
    {
        if (! $boq->status->isEditable()) {
            return $this->error("BOQ {$boq->code} cannot be deleted while status is {$boq->status->value}.", 422);
        }

        $boq->delete();

        return $this->ok(null, 'Deleted');
    }

    public function items(Boq $boq): JsonResponse
    {
        $items = $boq->items()
            ->with(['section', 'ahsp'])
            ->orderBy('section_id')
            ->orderBy('sort_order')
            ->get();

        return $this->ok(BoqItemResource::collection($items));
    }

    public function submit(Request $request, Boq $boq): JsonResponse
    {
        try {
            $boq->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new BoqResource($boq), 'BOQ submitted');
    }

    public function approve(Request $request, Boq $boq): JsonResponse
    {
        try {
            $boq->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new BoqResource($boq), 'BOQ approved');
    }

    public function reject(Request $request, Boq $boq): JsonResponse
    {
        try {
            $boq->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new BoqResource($boq), 'BOQ rejected');
    }

    public function newVersion(Boq $boq): JsonResponse
    {
        $copy = $this->service->copyVersion($boq);

        return $this->created(new BoqResource($copy->load('sections.items')), "New draft version v{$copy->version} created");
    }
}
