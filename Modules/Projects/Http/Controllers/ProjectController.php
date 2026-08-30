<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Exceptions\ProjectClosureException;
use Modules\Projects\Http\Requests\MppXmlImportRequest;
use Modules\Projects\Http\Requests\ProjectStoreRequest;
use Modules\Projects\Http\Requests\ProjectUpdateRequest;
use Modules\Projects\Http\Resources\ProjectResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\MppXmlImportService;
use Modules\Projects\Services\ProgressService;
use Modules\Projects\Services\ProjectClosureService;
use Modules\Projects\Services\ProjectService;

class ProjectController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly ProgressService $progress,
        private readonly ProjectClosureService $closure,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Project::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('city', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('project_manager_id'), fn ($query) => $query->where('project_manager_id', $request->integer('project_manager_id')))
            // 'Proyek saya' (Temuan 80): users.employee_id →
            // project_manager_id. Ya/Tidak are both real answers — Tidak must
            // exclude the caller's projects, not silently show everything. An
            // account with no employee link manages no projects, so mine=1 is
            // honestly empty for it; the comfortable fallback ("show all")
            // would mislead exactly the admin accounts that try the toggle
            // first.
            ->when($request->has('mine'), function ($query) use ($request): void {
                $employeeId = $request->user()?->employee_id;

                if ($request->boolean('mine')) {
                    $employeeId === null
                        ? $query->whereRaw('1 = 0')
                        : $query->where('project_manager_id', $employeeId);
                } elseif ($employeeId !== null) {
                    $query->where(fn ($not) => $not
                        ->whereNull('project_manager_id')
                        ->orWhere('project_manager_id', '!=', $employeeId));
                }
            })
            ->orderByDesc('id');

        return $this->listing($request, $query, ProjectResource::class,
            sortable: ['code', 'name', 'type', 'contract_value', 'actual_progress_pct', 'status']);
    }

    public function store(ProjectStoreRequest $request): JsonResponse
    {
        try {
            $project = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ProjectResource::make($project));
    }

    public function show(Project $project): JsonResponse
    {
        return $this->ok(ProjectResource::make(
            $project->load(['contract', 'customer', 'rootWbsTasks.children', 'milestones'])
        ));
    }

    public function update(ProjectUpdateRequest $request, Project $project): JsonResponse
    {
        try {
            $project = $this->service->update($project, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProjectResource::make($project));
    }

    public function destroy(Project $project): JsonResponse
    {
        try {
            $this->service->delete($project);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Project deleted.');
    }

    public function generateWbs(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'boq_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $project = $this->service->generateWbsFromBoq($project, $validated['boq_id'] ?? null);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            ProjectResource::make($project->load('rootWbsTasks.children')),
            'WBS generated from BOQ.'
        );
    }

    /**
     * Impor jadwal MS Project XML (P8, kriteria #8) — pohon WBS + baseline
     * lewat MppXmlImportService; semua penolakan (WBS sudah ada, XML rusak,
     * BAC tidak ada) adalah kalimat service, diteruskan sebagai 422.
     */
    public function importMppXml(MppXmlImportRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();
        $content = base64_decode((string) $data['content'], true);

        if ($content === false || $content === '') {
            return $this->error('Isi berkas bukan base64 yang dapat dibaca; unggah ulang berkas XML-nya.');
        }

        if (strlen($content) > MppXmlImportRequest::MAX_BYTES) {
            return $this->error('Berkas XML melebihi 5 MB.');
        }

        try {
            $result = app(MppXmlImportService::class)->import($project, $data['filename'], $content, [
                'baseline' => (bool) ($data['buat_baseline'] ?? true),
                'bac_override' => isset($data['bac_override']) ? (float) $data['bac_override'] : null,
                'by' => $request->user(),
            ]);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok([
            'tasks' => $result['tasks'],
            'baseline_code' => $result['baseline']?->code,
            'baseline_points' => $result['baseline']?->points()->count() ?? 0,
        ], sprintf('%d tugas WBS diimpor dari %s.', $result['tasks'], $data['filename']));
    }

    /**
     * The live open-items summary — what closing this project would step over,
     * read before anybody clicks. Its own endpoint rather than a field on
     * ProjectResource for BastController::prerequisites' reason: the list
     * screen would otherwise run three cross-module reads per row.
     */
    public function closure(Project $project): JsonResponse
    {
        return $this->ok($this->closure->evaluate($project));
    }

    /**
     * Tutup proyek — the explicit action the status dropdown now points to.
     *
     * ProjectClosureException is caught FIRST so the structured checklist can
     * ride along under `errors.closure`; it extends LogicException, so the
     * catch below would already have answered 422 with the same message.
     */
    public function close(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $project = $this->closure->close($project, $request->user(), $validated['override_reason'] ?? null);
        } catch (ProjectClosureException $e) {
            return $this->error($e->getMessage(), 422, ['closure' => $e->failures()]);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProjectResource::make($project), 'Proyek ditutup.');
    }

    public function sCurve(Project $project): JsonResponse
    {
        return $this->ok($this->progress->sCurveData($project));
    }

    public function dashboard(Project $project): JsonResponse
    {
        return $this->ok($this->service->dashboard($project));
    }
}
