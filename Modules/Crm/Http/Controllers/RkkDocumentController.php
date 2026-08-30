<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\RkkDocumentStoreRequest;
use Modules\Crm\Http\Requests\RkkDocumentUpdateRequest;
use Modules\Crm\Http\Requests\RkkIbprpLinksRequest;
use Modules\Crm\Http\Requests\RkkSmkkCostsRequest;
use Modules\Crm\Http\Resources\RkkDocumentResource;
use Modules\Crm\Models\RkkDocument;
use Modules\Crm\Services\RkkService;

/** RKK penawaran (Permen PUPR 10/2021). Cetak F/RKK. */
class RkkDocumentController extends ApiController
{
    public function __construct(private readonly RkkService $rkk) {}

    public function index(Request $request): JsonResponse
    {
        $query = RkkDocument::query()
            // smkkCosts.boqItem dimuat di sini karena kolom "Biaya SMKK" pada
            // daftar adalah jumlah baris RAB-nya: tanpa eager load, satu
            // halaman dua puluh RKK adalah dua puluh sapuan est_boq_items.
            ->with(['tenderPackage:id,code,title', 'smkkCosts.boqItem'])
            ->when($request->filled('tender_package_id'), fn ($q) => $q->where('tender_package_id', $request->integer('tender_package_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->orderByDesc('id');

        // with_rows=0 pada daftar: menyusun baris IBPRP dan biaya SMKK untuk
        // dua puluh RKK sekaligus adalah dua puluh sapuan register yang tidak
        // ditampilkan layar daftar mana pun.
        $request->merge(['with_rows' => $request->boolean('with_rows', false)]);

        return $this->listing($request, $query, RkkDocumentResource::class, sortable: ['code', 'title']);
    }

    public function store(RkkDocumentStoreRequest $request): JsonResponse
    {
        $rkk = $this->rkk->create($request->validated(), $request->user());

        // 'smkkCosts.boqItem', bukan 'smkkCosts' saja — di SEMUA endpoint
        // satu-dokumen, alasan yang sama dengan index(): smkkCosts yang sudah
        // termuat membuat smkkRows() melewatkan eager load-nya sendiri, dan
        // boqItem lalu di-lazy-load satu query per baris SMKK.
        return $this->created(
            RkkDocumentResource::make($rkk->load(['tenderPackage', 'ibprpLinks', 'smkkCosts.boqItem'])),
            'RKK dibuat.',
        );
    }

    public function show(RkkDocument $rkkDocument): JsonResponse
    {
        return $this->ok(RkkDocumentResource::make(
            $rkkDocument->load(['tenderPackage', 'ibprpLinks', 'smkkCosts.boqItem'])
        ));
    }

    public function update(RkkDocumentUpdateRequest $request, RkkDocument $rkkDocument): JsonResponse
    {
        $this->rkk->update($rkkDocument, $request->validated());

        return $this->ok(
            RkkDocumentResource::make($rkkDocument->load(['tenderPackage', 'ibprpLinks', 'smkkCosts.boqItem'])),
            'RKK diperbarui.',
        );
    }

    public function destroy(RkkDocument $rkkDocument): JsonResponse
    {
        $rkkDocument->delete();

        return $this->ok(null, 'RKK dihapus.');
    }

    public function syncIbprpLinks(RkkIbprpLinksRequest $request, RkkDocument $rkkDocument): JsonResponse
    {
        $this->rkk->syncIbprpLinks($rkkDocument, $request->validated()['ibprp_links']);

        return $this->ok(
            RkkDocumentResource::make($rkkDocument->load(['tenderPackage', 'ibprpLinks', 'smkkCosts.boqItem'])),
            'Tautan IBPRP diperbarui.',
        );
    }

    public function syncSmkkCosts(RkkSmkkCostsRequest $request, RkkDocument $rkkDocument): JsonResponse
    {
        $this->rkk->syncSmkkCosts($rkkDocument, $request->validated()['smkk_costs']);

        return $this->ok(
            RkkDocumentResource::make($rkkDocument->load(['tenderPackage', 'ibprpLinks', 'smkkCosts.boqItem'])),
            'Baris biaya SMKK diperbarui.',
        );
    }
}
