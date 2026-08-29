<?php

namespace Modules\Assets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Assets\Enums\AssetOwnership;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Http\Requests\AssetDeployRequest;
use Modules\Assets\Http\Requests\AssetDisposeRequest;
use Modules\Assets\Http\Requests\AssetStoreRequest;
use Modules\Assets\Http\Requests\AssetUpdateRequest;
use Modules\Assets\Http\Resources\AssetResource;
use Modules\Assets\Http\Resources\DeploymentResource;
use Modules\Assets\Http\Resources\EquipmentLogResource;
use Modules\Assets\Http\Resources\MaintenanceResource;
use Modules\Assets\Models\Asset;
use Modules\Assets\Services\AssetDisposalService;
use Modules\Assets\Services\AssetRegisterService;
use Modules\Assets\Services\DeploymentService;
use Modules\Core\Http\ApiController;

class AssetController extends ApiController
{
    public function __construct(
        private readonly DeploymentService $deploymentService,
        private readonly AssetDisposalService $disposalService,
        private readonly AssetRegisterService $register,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Asset::query()
            ->with('category')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('brand', 'like', "%{$q}%")
                        ->orWhere('serial_no', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('current_project_id', $request->integer('project_id')))
            ->orderBy('code');

        return $this->listing($request, $query, AssetResource::class,
            sortable: ['code', 'name', 'serial_no', 'acquisition_cost', 'book_value', 'status'],
            dateColumn: 'acquisition_date');
    }

    public function store(AssetStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['accumulated_depreciation'] = 0;
        $data['status'] = AssetStatus::Available;

        if (($data['ownership'] ?? 'owned') === AssetOwnership::Rented->value) {
            // P5 — alat sewa: tidak pernah dibeli, jadi kolom perolehan NULL
            // (bukan Rp 0) dan nilai bukunya NULL — bergaris di layar dan
            // cetakan, karena alat ini tidak ada di neraca kita. Penyusutan
            // tidak pernah menyentuhnya (gate ownership di
            // DepreciationService::runForPeriod).
            $data['acquisition_date'] = null;
            $data['acquisition_cost'] = null;
            $data['salvage_value'] = 0;
            $data['useful_life_months'] = 0;
            $data['depreciation_start_date'] = null;
            $data['book_value'] = null;
        } else {
            $data['salvage_value'] = $data['salvage_value'] ?? 0;
            // Depreciation starts in the acquisition month unless stated otherwise
            // (praktik umum fiskal Indonesia: penyusutan dimulai bulan perolehan).
            $data['depreciation_start_date'] = $data['depreciation_start_date'] ?? $data['acquisition_date'];
            $data['book_value'] = $data['acquisition_cost'];
        }

        $asset = Asset::query()->create($data);

        return $this->created(AssetResource::make($asset->load('category')));
    }

    public function show(Asset $asset): JsonResponse
    {
        return $this->ok(AssetResource::make($asset->load('category', 'activeDeployment')));
    }

    public function update(AssetUpdateRequest $request, Asset $asset): JsonResponse
    {
        // Penjaga status pindah ke AssetRegisterService: keputusannya harus
        // jatuh pada baris yang dibaca ulang di dalam transaksi, bukan pada
        // instance route binding — pelepasan yang commit di jendela antara
        // binding dan tulisan dulu tidak terlihat dari sini.
        try {
            $asset = $this->register->update($asset, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(AssetResource::make($asset));
    }

    public function destroy(Asset $asset): JsonResponse
    {
        try {
            $this->register->delete($asset);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Asset deleted.');
    }

    public function deploy(AssetDeployRequest $request, Asset $asset): JsonResponse
    {
        try {
            $deployment = $this->deploymentService->deploy($asset, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(DeploymentResource::make($deployment), 'Asset deployed to project.');
    }

    /**
     * Hapus buku / jual — the ONLY path to status disposed. Posts the
     * derecognition journal (cost out, accumulated depreciation out, gain or
     * loss recognised) and stamps the disposal fields in one transaction.
     */
    public function dispose(AssetDisposeRequest $request, Asset $asset): JsonResponse
    {
        try {
            $asset = $this->disposalService->dispose($asset, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(AssetResource::make($asset), "Aset {$asset->code} dihapusbukukan dan jurnal pelepasan diposting.");
    }

    /**
     * Full trail for one asset: deployments, maintenances, depreciation entries.
     */
    public function history(Asset $asset): JsonResponse
    {
        $asset->load([
            'category',
            'deployments' => fn ($query) => $query->orderByDesc('deployed_from'),
            'maintenances' => fn ($query) => $query->orderByDesc('maintenance_date'),
            'depreciationEntries' => fn ($query) => $query->with('run')->orderByDesc('id'),
            // The BBM & hour-meter register, newest first like its siblings.
            // Through live deployments only — a deleted mobilisation's
            // readings leave the trail with it (see Asset::equipmentLogs).
            'equipmentLogs' => fn ($query) => $query->with(['deployment', 'loggedBy'])
                ->orderByDesc('log_date')->orderByDesc('ast_equipment_logs.id'),
        ]);

        return $this->ok([
            'asset' => AssetResource::make($asset),
            'deployments' => DeploymentResource::collection($asset->deployments),
            'maintenances' => MaintenanceResource::collection($asset->maintenances),
            'equipment_logs' => EquipmentLogResource::collection($asset->equipmentLogs),
            'depreciation_entries' => $asset->depreciationEntries->map(fn ($entry) => [
                'id' => $entry->id,
                'run_code' => $entry->run?->code,
                'period' => $entry->run?->periodLabel(),
                'run_status' => $entry->run?->status?->value,
                'amount' => $entry->amount,
                'book_value_after' => $entry->book_value_after,
            ])->values(),
        ]);
    }
}
