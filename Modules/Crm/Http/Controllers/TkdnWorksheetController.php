<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\TkdnWorksheetItemsRequest;
use Modules\Crm\Http\Requests\TkdnWorksheetStoreRequest;
use Modules\Crm\Http\Resources\TkdnWorksheetResource;
use Modules\Crm\Models\TkdnWorksheet;
use Modules\Crm\Services\TkdnService;

/**
 * Lembar hitung TKDN Jasa (Permenperin 35/2025). Aritmetikanya, sumbernya dan
 * aturan "baris belum dinilai" seluruhnya ada di TkdnService.
 */
class TkdnWorksheetController extends ApiController
{
    public function __construct(private readonly TkdnService $tkdn) {}

    public function index(Request $request): JsonResponse
    {
        $query = TkdnWorksheet::query()
            // quotation.items ikut dimuat karena summary() menyapu SETIAP baris
            // penawaran untuk menghitung cakupan — tanpa itu satu halaman dua
            // puluh lembar adalah dua puluh sapuan crm_quotation_items.
            ->with(['quotation:id,code,title,total', 'quotation.items', 'items'])
            ->when($request->filled('quotation_id'), fn ($q) => $q->where('quotation_id', $request->integer('quotation_id')))
            ->when($request->filled('tender_package_id'), fn ($q) => $q->where('tender_package_id', $request->integer('tender_package_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, TkdnWorksheetResource::class, sortable: ['code']);
    }

    public function store(TkdnWorksheetStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $worksheet = $this->tkdn->createWorksheet($data, $request->user());

        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $this->tkdn->replaceItems($worksheet, $data['items']);
        }

        return $this->created(
            TkdnWorksheetResource::make($worksheet->load(['quotation.items', 'items'])),
            'Lembar TKDN dibuat.',
        );
    }

    public function show(TkdnWorksheet $tkdnWorksheet): JsonResponse
    {
        return $this->ok(TkdnWorksheetResource::make($tkdnWorksheet->load(['quotation.items', 'items'])));
    }

    public function update(Request $request, TkdnWorksheet $tkdnWorksheet): JsonResponse
    {
        $data = $request->validate([
            'tender_package_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
        ] + TkdnWorksheetItemsRequest::lineRules());

        $this->tkdn->updateWorksheet($tkdnWorksheet, $data);

        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $this->tkdn->replaceItems($tkdnWorksheet, $data['items']);
        }

        return $this->ok(
            TkdnWorksheetResource::make($tkdnWorksheet->load(['quotation.items', 'items'])),
            'Lembar TKDN diperbarui.',
        );
    }

    public function destroy(TkdnWorksheet $tkdnWorksheet): JsonResponse
    {
        $tkdnWorksheet->delete();

        return $this->ok(null, 'Lembar TKDN dihapus.');
    }

    public function replaceItems(TkdnWorksheetItemsRequest $request, TkdnWorksheet $tkdnWorksheet): JsonResponse
    {
        $this->tkdn->replaceItems($tkdnWorksheet, $request->validated()['items']);

        return $this->ok(
            TkdnWorksheetResource::make($tkdnWorksheet->load(['quotation.items', 'items'])),
            'Rincian komponen TKDN diperbarui.',
        );
    }

    /**
     * Persentase paket — SELALU bersama cakupannya. Tidak ada endpoint yang
     * mengembalikan angkanya sendirian, karena klien yang bisa mengambilnya
     * sendirian akan mencetaknya sendirian.
     */
    public function summary(TkdnWorksheet $tkdnWorksheet): JsonResponse
    {
        return $this->ok($this->tkdn->summary($tkdnWorksheet));
    }
}
