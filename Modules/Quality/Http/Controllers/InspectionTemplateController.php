<?php

namespace Modules\Quality\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Quality\Http\Requests\InspectionTemplateStoreRequest;
use Modules\Quality\Http\Requests\InspectionTemplateUpdateRequest;
use Modules\Quality\Http\Resources\InspectionTemplateResource;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Quality\Services\InspectionTemplateService;

class InspectionTemplateController extends ApiController
{
    public function __construct(private readonly InspectionTemplateService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = InspectionTemplate::query()
            ->withCount('items')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('work_package', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('stage'), fn ($query) => $query->where('stage', $request->string('stage')))
            ->when($request->filled('jenis'), fn ($query) => $query->where('jenis', $request->string('jenis')))
            ->orderBy('code');

        return $this->listing($request, $query, InspectionTemplateResource::class,
            sortable: ['code', 'work_package', 'stage']);
    }

    public function store(InspectionTemplateStoreRequest $request): JsonResponse
    {
        $template = $this->service->create($request->validated());

        return $this->created(InspectionTemplateResource::make($template->load('items')));
    }

    public function show(InspectionTemplate $template): JsonResponse
    {
        return $this->ok(InspectionTemplateResource::make($template->load('items')));
    }

    public function update(InspectionTemplateUpdateRequest $request, InspectionTemplate $template): JsonResponse
    {
        $updated = $this->service->update($template, $request->validated());

        return $this->ok(InspectionTemplateResource::make($updated->load('items')));
    }

    public function destroy(InspectionTemplate $template): JsonResponse
    {
        $template->delete();

        return $this->ok(null, 'Template inspeksi dihapus.');
    }
}
