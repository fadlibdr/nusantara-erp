<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\AwardDecisionStoreRequest;
use Modules\Procurement\Http\Requests\AwardDecisionUpdateRequest;
use Modules\Procurement\Http\Resources\AwardDecisionResource;
use Modules\Procurement\Models\AwardDecision;
use Modules\Procurement\Services\AwardDecisionService;

class AwardDecisionController extends ApiController
{
    public function __construct(private readonly AwardDecisionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = AwardDecision::query()
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('rfq_id'), fn ($q) => $q->where('rfq_id', $request->integer('rfq_id')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['vendor', 'rfq'])
            ->orderByDesc('id');

        return $this->listing($request, $query, AwardDecisionResource::class,
            sortable: ['code', 'awarded_amount', 'status']);
    }

    public function store(AwardDecisionStoreRequest $request): JsonResponse
    {
        try {
            $award = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(AwardDecisionResource::make($award));
    }

    public function show(AwardDecision $awardDecision): JsonResponse
    {
        return $this->ok(AwardDecisionResource::make($awardDecision->load('vendor', 'rfq')));
    }

    public function update(AwardDecisionUpdateRequest $request, AwardDecision $awardDecision): JsonResponse
    {
        try {
            $award = $this->service->update($awardDecision, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(AwardDecisionResource::make($award));
    }

    public function destroy(AwardDecision $awardDecision): JsonResponse
    {
        try {
            $this->service->delete($awardDecision);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Keputusan pemenang dihapus.');
    }

    public function submit(Request $request, AwardDecision $awardDecision): JsonResponse
    {
        try {
            $award = $this->service->submit($awardDecision, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(AwardDecisionResource::make($award), 'Keputusan pemenang diajukan.');
    }

    public function approve(Request $request, AwardDecision $awardDecision): JsonResponse
    {
        try {
            $award = $this->service->approve($awardDecision, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        $message = $award->status->value === 'approved'
            ? 'Keputusan pemenang disetujui.'
            : sprintf(
                'Persetujuan tercatat; jenjang membutuhkan %d penyetuju berbeda, menunggu tingkat berikutnya.',
                $award->requiredApprovalLevels(),
            );

        return $this->ok(AwardDecisionResource::make($award), $message);
    }

    public function reject(Request $request, AwardDecision $awardDecision): JsonResponse
    {
        try {
            $award = $this->service->reject($awardDecision, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(AwardDecisionResource::make($award), 'Keputusan pemenang ditolak.');
    }
}
