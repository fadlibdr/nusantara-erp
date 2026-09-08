<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\ActivityStoreRequest;
use Modules\Crm\Http\Requests\ActivityUpdateRequest;
use Modules\Crm\Http\Resources\ActivityResource;
use Modules\Crm\Models\Activity;
use Modules\Crm\Services\ActivityService;

/**
 * Register aktivitas CRM. Bukan dokumen: tanpa nomor, tanpa persetujuan.
 *
 * Semua tulisan lewat ActivityService — satu pintu, karena setiap perubahan di
 * sini menggeser `crm_leads.next_follow_up_at` (turunan, F-3 / T3.3).
 */
class ActivityController extends ApiController
{
    public function __construct(private readonly ActivityService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Activity::query()
            ->with(['owner:id,name', 'doneBy:id,name'])
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->string('document_type')))
            ->when($request->filled('document_id'), fn ($q) => $q->where('document_id', $request->integer('document_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('owner_user_id'), fn ($q) => $q->where('owner_user_id', $request->integer('owner_user_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('subject', 'like', '%'.$request->string('q').'%'))
            /*
             * Tiga keadaan, bukan satu bendera: "terbuka", "selesai", dan
             * "lewat tanggal" — yang ketiga adalah bagian dari yang pertama,
             * dan menggabungkannya menjadi satu saringan boolean membuat
             * antrean kerja harian mustahil ditanyakan.
             */
            ->when($request->string('state')->toString() === 'open', fn ($q) => $q->whereNull('done_at'))
            ->when($request->string('state')->toString() === 'done', fn ($q) => $q->whereNotNull('done_at'))
            ->when($request->string('state')->toString() === 'overdue', fn ($q) => $q
                ->whereNull('done_at')
                ->whereNotNull('due_at')
                // '<' terhadap HARI INI: sebuah aktivitas yang jatuh tempo hari
                // ini belum terlambat, dan kolomnya bertipe date (aturan
                // rentang setengah terbuka WatchedDeadlines).
                ->where('due_at', '<', Carbon::today()->toDateString()))
            // Yang paling mendesak di atas; yang tanpa tanggal di bawahnya, dan
            // TIDAK dianggap jatuh tempo tahun 1970 (nullsLast di kedua driver
            // ditulis eksplisit sebagai ekspresi boolean).
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('id');

        return $this->listing($request, $query, ActivityResource::class,
            sortable: ['subject', 'type', 'due_at', 'done_at'], dateColumn: 'due_at');
    }

    public function store(ActivityStoreRequest $request): JsonResponse
    {
        $activity = $this->service->create($request->validated());

        return $this->created(
            ActivityResource::make($activity->load(['owner:id,name', 'doneBy:id,name'])),
            'Aktivitas dicatat.',
        );
    }

    public function show(Activity $activity): JsonResponse
    {
        return $this->ok(ActivityResource::make($activity->load(['owner:id,name', 'doneBy:id,name'])));
    }

    public function update(ActivityUpdateRequest $request, Activity $activity): JsonResponse
    {
        $activity = $this->service->update($activity, $request->validated());

        return $this->ok(ActivityResource::make($activity->load(['owner:id,name', 'doneBy:id,name'])));
    }

    public function destroy(Activity $activity): JsonResponse
    {
        $this->service->delete($activity);

        return $this->ok(null, 'Aktivitas dihapus.');
    }

    public function markDone(Request $request, Activity $activity): JsonResponse
    {
        $activity = $this->service->markDone($activity, $request->user());

        return $this->ok(
            ActivityResource::make($activity->load(['owner:id,name', 'doneBy:id,name'])),
            "Aktivitas \"{$activity->subject}\" ditandai selesai.",
        );
    }

    public function reopen(Activity $activity): JsonResponse
    {
        $activity = $this->service->reopen($activity);

        return $this->ok(
            ActivityResource::make($activity->load(['owner:id,name', 'doneBy:id,name'])),
            "Aktivitas \"{$activity->subject}\" dibuka kembali.",
        );
    }
}
