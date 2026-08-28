<?php

namespace Modules\Engineering\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Engineering\Enums\DrawingStatus;
use Modules\Engineering\Http\Requests\DrawingStoreRequest;
use Modules\Engineering\Http\Requests\DrawingUpdateRequest;
use Modules\Engineering\Http\Resources\DrawingResource;
use Modules\Engineering\Models\Drawing;

/**
 * The shop-drawing register (FM-10-01/21). `status` is never written here —
 * it mirrors the submittals, moved by DrawingSubmittalService.
 */
class DrawingController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Drawing::query()
            ->with(['project', 'currentSubmittal'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('number', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('discipline'), fn ($query) => $query->where('discipline', $request->string('discipline')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('number');

        return $this->listing($request, $query, DrawingResource::class,
            sortable: ['number', 'title', 'discipline', 'planned_submit_date', 'status'],
            dateColumn: 'planned_submit_date');
    }

    public function store(DrawingStoreRequest $request): JsonResponse
    {
        // Status written explicitly: a DB default is not hydrated on the
        // freshly created model (the WorkPermitService lesson), and the
        // resource would answer `status: null` for a drawing that IS
        // unsubmitted.
        $drawing = Drawing::query()->create(
            $request->validated() + ['status' => DrawingStatus::BelumDiajukan],
        );

        return $this->created(DrawingResource::make($drawing->load('project')));
    }

    public function show(Drawing $drawing): JsonResponse
    {
        return $this->ok(DrawingResource::make(
            $drawing->load(['project', 'currentSubmittal', 'submittals' => fn ($query) => $query->orderByDesc('id')]),
        ));
    }

    public function update(DrawingUpdateRequest $request, Drawing $drawing): JsonResponse
    {
        $drawing->fill($request->validated())->save();

        return $this->ok(DrawingResource::make($drawing->load('project')));
    }

    public function destroy(Drawing $drawing): JsonResponse
    {
        if ($drawing->submittals()->exists()) {
            return $this->error(sprintf(
                'Gambar %s sudah punya riwayat pengajuan dan tidak dapat dihapus dari register.',
                $drawing->number,
            ));
        }

        $drawing->delete();

        return $this->ok(null, 'Gambar dihapus dari register.');
    }
}
