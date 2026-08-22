<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Exceptions\BastPrerequisiteException;
use Modules\Projects\Http\Requests\BastApproveRequest;
use Modules\Projects\Http\Requests\BastStoreRequest;
use Modules\Projects\Http\Requests\BastUpdateRequest;
use Modules\Projects\Http\Resources\BastResource;
use Modules\Projects\Models\Bast;
use Modules\Projects\Services\BastPrerequisiteService;
use Modules\Projects\Services\ProjectService;

class BastController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly BastPrerequisiteService $prerequisites,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Bast::query()
            ->with('project')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('customer_representative', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('bast_type'), fn ($query) => $query->where('bast_type', $request->string('bast_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, BastResource::class,
            sortable: ['code', 'bast_type', 'handover_date', 'retention_release_due', 'status'], dateColumn: 'handover_date');
    }

    public function store(BastStoreRequest $request): JsonResponse
    {
        try {
            $bast = $this->service->createBast($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(BastResource::make($bast));
    }

    public function show(Bast $bast): JsonResponse
    {
        return $this->ok(BastResource::make($bast->load('project')));
    }

    public function update(BastUpdateRequest $request, Bast $bast): JsonResponse
    {
        if (! $bast->status->isEditable()) {
            return $this->error("BAST {$bast->code} is {$bast->status->value} and can no longer be edited.");
        }

        $bast->fill($request->validated())->save();

        return $this->ok(BastResource::make($bast));
    }

    public function destroy(Bast $bast): JsonResponse
    {
        if (! $bast->status->isEditable()) {
            return $this->error("BAST {$bast->code} is {$bast->status->value} and can no longer be deleted.");
        }

        $bast->delete();

        return $this->ok(null, 'BAST deleted.');
    }

    public function submit(Request $request, Bast $bast): JsonResponse
    {
        try {
            $bast->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(BastResource::make($bast), 'BAST submitted for approval.');
    }

    /**
     * Approving BAST I moves the project into masa pemeliharaan (warranty);
     * approving BAST II closes the project and releases the retensi — handled in
     * the service, which runs the prerequisite checklist first.
     *
     * BastPrerequisiteException is caught FIRST so the structured checklist can
     * ride along under `errors`. It extends LogicException, so the catch below
     * would already have answered 422 with the same message — this only adds the
     * machine-readable half.
     */
    public function approve(BastApproveRequest $request, Bast $bast): JsonResponse
    {
        try {
            $bast = $this->service->approveBast(
                $bast,
                $request->user(),
                $request->input('note'),
                $request->input('override_reason'),
            );
        } catch (BastPrerequisiteException $e) {
            return $this->error($e->getMessage(), 422, ['prerequisites' => $e->failures()]);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(BastResource::make($bast->load('project')), 'BAST approved.');
    }

    /**
     * The live checklist for one BAST, so the approver sees what the click will
     * cost before making it — the retensi at stake above all.
     *
     * Its own endpoint rather than a field on BastResource: the list screen would
     * otherwise run one full evaluation, with three cross-module reads, per row.
     */
    public function prerequisites(Bast $bast): JsonResponse
    {
        return $this->ok($this->prerequisites->evaluate($bast->load('project')));
    }

    public function reject(Request $request, Bast $bast): JsonResponse
    {
        try {
            $bast->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(BastResource::make($bast), 'BAST rejected.');
    }
}
