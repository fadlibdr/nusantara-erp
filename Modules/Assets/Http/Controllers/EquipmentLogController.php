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
     *
     * AND IT SAYS WHAT THE NEXT READING DOES *NOT* FIX (verifikasi F-7).
     * The old wording promised "corrected by the next reading" without a
     * caveat, and for the F-7 hour alarm that promise is not kept: service
     * due is judged on the HIGHEST reading of the asset
     * (MaintenanceDueService definition 1), so a figure typed one digit TOO
     * HIGH keeps being the judged one however many correct rows follow it.
     * Measured on a copy of the demo DB (php -S 127.0.0.1:8195): a 33.755
     * typed on top of 3.375,5 with a 3.400-hour target leaves the asset at
     * "Melampaui batas · lewat 30.355 jam", and all four doors are shut —
     * POST 3.400 on the same deployment 422 (monotone guard), PUT 405-in-
     * words, DELETE 405-in-words, and a correction row on a NEW deployment
     * is accepted and changes nothing. Telling the user to do something that
     * will not work is worse than refusing.
     */
    private const REGISTER_IS_APPEND_ONLY = 'Baris register tidak diubah dan tidak dihapus — '
        .'register pembacaan hanya bisa DITAMBAH, tidak disunting. '
        .'Catat baris log baru dengan angka yang benar dan sebutkan koreksinya di catatan; '
        .'baris lama tetap terbaca di riwayat. Satu hal yang TIDAK diperbaiki baris baru itu: '
        .'jatuh tempo servis dihakimi dari pembacaan TERTINGGI alat, jadi angka yang telanjur '
        .'diketik terlalu tinggi tetap yang dihakimi — kartu alatnya akan menyebutkan bahwa '
        .'pembacaan terakhirnya lebih rendah, dan menurunkan angka tertinggi itu belum ada jalannya.';

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
