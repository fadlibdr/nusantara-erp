<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\WbsTaskProgressRequest;
use Modules\Projects\Http\Requests\WbsTaskStoreRequest;
use Modules\Projects\Http\Resources\WbsTaskResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Modules\Projects\Services\ProgressService;

class WbsTaskController extends ApiController
{
    public function __construct(private readonly ProgressService $progress) {}

    /**
     * WBS tree of a project: root tasks with their children (two levels deep,
     * which covers the section -> item structure generated from a BOQ).
     */
    public function index(Project $project): JsonResponse
    {
        $tasks = $project->rootWbsTasks()->with('children.children')->get();

        return $this->ok(WbsTaskResource::collection($tasks));
    }

    /**
     * Flat, paginated listing for the Inventory issue-form picker — the lookup
     * that fills inv_issue_items.wbs_task_id, which is what the material
     * variance attribution hangs off. Leaves only by default: a parent has no
     * BOQ item by construction, so material charged to it can never be compared
     * against an analisa harga satuan. The list spans every project because the
     * SPA's form has no dependent lookups — hence project_code/picker_label on
     * the resource ('PRJ-2026-001 · B.3') as the disambiguator, and the
     * server-side guard in Inventory that refuses another project's package.
     */
    public function list(Request $request): JsonResponse
    {
        $query = WbsTask::query()
            ->with('project')
            ->when($request->boolean('leaf', true), fn ($query) => $query->whereDoesntHave('children'))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($where) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $where->where('wbs_code', 'like', $term)->orWhere('name', 'like', $term);
            }))
            ->orderBy('project_id')
            ->orderBy('sort_order')
            ->orderBy('wbs_code');

        // Empty whitelist, no date column: this is a picker source, not a
        // screen — the tree order above is semantic and must not be replaced.
        // listing() only adds the uniform meta (and refuses any ?sort).
        return $this->listing($request, $query, WbsTaskResource::class);
    }

    public function store(WbsTaskStoreRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        $task = $project->wbsTasks()->create([
            'parent_id' => $data['parent_id'] ?? null,
            'boq_item_id' => $data['boq_item_id'] ?? null,
            'wbs_code' => $data['wbs_code'],
            'name' => $data['name'],
            'weight_pct' => round((float) ($data['weight_pct'] ?? 0), 4),
            'planned_start' => $data['planned_start'] ?? null,
            'planned_end' => $data['planned_end'] ?? null,
            'progress_pct' => 0,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return $this->created(WbsTaskResource::make($task));
    }

    public function progress(WbsTaskProgressRequest $request, WbsTask $wbsTask): JsonResponse
    {
        $data = $request->validated();

        try {
            $wbsTask = $this->progress->updateTaskProgress(
                $wbsTask,
                (float) $data['progress_pct'],
                $data['actual_start'] ?? null,
                $data['actual_end'] ?? null,
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WbsTaskResource::make($wbsTask), 'Task progress updated and rolled up.');
    }
}
