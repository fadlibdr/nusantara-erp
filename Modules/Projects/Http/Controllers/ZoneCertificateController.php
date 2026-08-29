<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\ZoneCertificateStoreRequest;
use Modules\Projects\Http\Requests\ZoneCertificateUpdateRequest;
use Modules\Projects\Http\Resources\ZoneCertificateResource;
use Modules\Projects\Models\ZoneCertificate;
use Modules\Projects\Services\ZoneCertificateService;

class ZoneCertificateController extends ApiController
{
    public function __construct(private readonly ZoneCertificateService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ZoneCertificate::query()
            ->with(['project', 'location'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('notes', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, ZoneCertificateResource::class,
            sortable: ['code', 'status', 'certified_at'], dateColumn: 'certified_at');
    }

    public function store(ZoneCertificateStoreRequest $request): JsonResponse
    {
        try {
            $certificate = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ZoneCertificateResource::make($certificate));
    }

    public function show(ZoneCertificate $zoneCertificate): JsonResponse
    {
        return $this->ok(ZoneCertificateResource::make($zoneCertificate->load(['project', 'location'])));
    }

    public function update(ZoneCertificateUpdateRequest $request, ZoneCertificate $zoneCertificate): JsonResponse
    {
        try {
            $certificate = $this->service->update($zoneCertificate, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ZoneCertificateResource::make($certificate));
    }

    public function destroy(ZoneCertificate $zoneCertificate): JsonResponse
    {
        $this->service->delete($zoneCertificate);

        return $this->ok(null, 'Berita acara pemeriksaan dihapus.');
    }
}
