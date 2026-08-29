<?php

namespace Modules\Subcontract\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Services\VendorQualificationService;
use Modules\Subcontract\Http\Requests\LaborContractStoreRequest;
use Modules\Subcontract\Http\Requests\LaborContractUpdateRequest;
use Modules\Subcontract\Http\Resources\LaborContractResource;
use Modules\Subcontract\Models\LaborContract;
use Modules\Subcontract\Services\LaborContractService;

class LaborContractController extends ApiController
{
    public function __construct(
        private readonly LaborContractService $service,
        private readonly VendorQualificationService $qualification,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = LaborContract::query()
            ->with('vendor', 'project')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, LaborContractResource::class,
            sortable: ['code', 'title', 'value', 'start_date', 'status'],
            dateColumn: 'start_date');
    }

    public function store(LaborContractStoreRequest $request): JsonResponse
    {
        try {
            // VendorNotQualifiedException extends LogicException by design,
            // so the one catch answers both refusals as a 422.
            $contract = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(LaborContractResource::make($contract));
    }

    public function show(LaborContract $laborContract): JsonResponse
    {
        return $this->ok(LaborContractResource::make(
            $laborContract->load('items', 'claims', 'vendor', 'project')
        ));
    }

    public function update(LaborContractUpdateRequest $request, LaborContract $laborContract): JsonResponse
    {
        try {
            $contract = $this->service->update($laborContract, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborContractResource::make($contract));
    }

    public function destroy(LaborContract $laborContract): JsonResponse
    {
        try {
            $this->service->delete($laborContract);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'SP3 dihapus.');
    }

    public function submit(Request $request, LaborContract $laborContract): JsonResponse
    {
        try {
            // Gate prakualifikasi berdiri saat MENGAJUKAN, bukan hanya saat
            // membuat draf — cermin SubcontractController::submit: K3L/pakta
            // bisa kedaluwarsa (dan mandornya dinonaktifkan) di antara draf
            // dan pengajuan, dan update() bebas menukar vendor_id.
            $vendor = $laborContract->vendor;

            if ($vendor === null) {
                return $this->error("Vendor SP3 {$laborContract->code} sudah dihapus; pilih mandor lain sebelum mengajukan.");
            }

            $reason = trim((string) $request->input('qualification_override_reason', ''));
            $overridden = $this->qualification->assertQualified($vendor, $reason === '' ? null : $reason);

            $laborContract = DB::transaction(function () use ($laborContract, $request, $reason, $overridden): LaborContract {
                /** @var LaborContract $contract */
                $contract = LaborContract::query()->whereKey($laborContract->id)->lockForUpdate()->firstOrFail();

                // submit() DULU, alasan sesudahnya, satu transaksi — pengajuan
                // yang ditolak Approvable tidak boleh meninggalkan jejak
                // override palsu (pola SPK).
                $contract->submit($request->user());

                if ($overridden !== []) {
                    $contract->forceFill(['qualification_override_reason' => $reason])->save();
                }

                return $contract;
            });
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborContractResource::make($laborContract), 'SP3 diajukan.');
    }

    public function approve(Request $request, LaborContract $laborContract): JsonResponse
    {
        try {
            $contract = $this->service->approve($laborContract, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborContractResource::make($contract), 'SP3 disetujui.');
    }

    public function reject(Request $request, LaborContract $laborContract): JsonResponse
    {
        try {
            $laborContract->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborContractResource::make($laborContract), 'SP3 ditolak.');
    }
}
