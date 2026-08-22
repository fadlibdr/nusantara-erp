<?php

namespace Modules\Assets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Assets\Http\Requests\EquipmentLogStoreRequest;
use Modules\Assets\Http\Resources\EquipmentLogResource;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;
use Modules\Assets\Services\EquipmentLogService;
use Modules\Core\Http\ApiController;

/**
 * Log BBM & jam alat — a register, so the surface is deliberately small:
 * list and append. No update, no delete (see the two refusals below).
 */
class EquipmentLogController extends ApiController
{
    public function __construct(private readonly EquipmentLogService $service) {}

    /**
     * The one sentence both refused verbs say. It is a policy answer, not a
     * missing route: a register of readings is corrected by the NEXT reading,
     * never by editing history — the trail under a mechanic's utilisation
     * math must be the trail the site actually wrote.
     */
    private const REGISTER_IS_APPEND_ONLY = 'Baris register tidak diubah dan tidak dihapus — '
        .'register pembacaan dikoreksi oleh pembacaan berikutnya, bukan dengan menyunting riwayat. '
        .'Catat baris log baru dengan angka yang benar dan sebutkan koreksinya di catatan.';

    public function index(Request $request): JsonResponse
    {
        /*
         * withTrashed pada deployment (dan whereHas-nya): mobilisasi yang
         * sudah dihapus lunak tetap punya log — bacaan meter itu terjadi, dan
         * baris register yang kehilangan nama mesinnya menyembunyikan alat,
         * bukan lognya. Tanpa ini daftar menampilkan baris tanpa nama (kolom
         * Aset kosong) sementara filter asset_id/project_id justru
         * menghilangkannya — dua jawaban berbeda untuk satu pertanyaan.
         * Aturan registri cetak yang sama: withTrashed pada setiap belongsTo
         * ke model yang menghapus lunak.
         */
        $query = EquipmentLog::query()
            ->with(['deployment' => fn ($q) => $q->withTrashed()->with('asset'), 'loggedBy'])
            ->when($request->filled('deployment_id'), fn ($query) => $query->where('deployment_id', $request->integer('deployment_id')))
            ->when($request->filled('asset_id'), fn ($query) => $query->whereHas(
                'deployment', fn ($deployment) => $deployment->withTrashed()->where('asset_id', $request->integer('asset_id'))
            ))
            ->when($request->filled('project_id'), fn ($query) => $query->whereHas(
                'deployment', fn ($deployment) => $deployment->withTrashed()->where('project_id', $request->integer('project_id'))
            ))
            ->orderByDesc('log_date')
            ->orderByDesc('id');

        return $this->listing($request, $query, EquipmentLogResource::class,
            sortable: ['log_date', 'hour_meter', 'fuel_liters'],
            dateColumn: 'log_date');
    }

    public function store(EquipmentLogStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $deployment = Deployment::query()->findOrFail($data['deployment_id']);

        try {
            $log = $this->service->record($deployment, $data, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(EquipmentLogResource::make($log->load(['deployment.asset', 'loggedBy'])));
    }

    /** Registered only to refuse in words; a bare 404 reads as a broken deploy. */
    public function update(EquipmentLog $equipmentLog): JsonResponse
    {
        return $this->error(self::REGISTER_IS_APPEND_ONLY);
    }

    /** Same refusal as update — deleting history is editing it. */
    public function destroy(EquipmentLog $equipmentLog): JsonResponse
    {
        return $this->error(self::REGISTER_IS_APPEND_ONLY);
    }
}
