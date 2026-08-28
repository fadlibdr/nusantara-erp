<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Projects\Http\Requests\DailyReportStoreRequest;
use Modules\Projects\Http\Requests\DailyReportUpdateRequest;
use Modules\Projects\Http\Resources\DailyReportResource;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Services\DailyReportService;

class DailyReportController extends ApiController
{
    public function __construct(private readonly DailyReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = DailyReport::query()
            ->with(['project', 'materials'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('activities', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('report_date');

        // The local date_from/date_to whens moved into listing(): identical
        // predicate, same param names, one implementation.
        return $this->listing($request, $query, DailyReportResource::class,
            sortable: ['code', 'report_date', 'manpower_count'], dateColumn: 'report_date');
    }

    public function store(DailyReportStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        // The isOperational guard throws here; uncaught it would be a 500, and
        // a mandor met by "Server Error" retypes the report instead of reading
        // why the closed project refused it.
        try {
            $report = $this->service->create($data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(DailyReportResource::make($report));
    }

    public function show(DailyReport $dailyReport): JsonResponse
    {
        return $this->ok(DailyReportResource::make($dailyReport->load([
            'project', 'materials', 'manpower', 'equipment', 'receipts', 'activityLines',
        ])));
    }

    /**
     * GRN terposting pada site & tanggal laporan ini — bahan impor untuk tabel
     * MATERIAL MASUK, per baris item, TIDAK pernah diimpor otomatis: pengawas
     * memilih (P0-A).
     *
     * "Proyek yang sama" berarti GUDANG SITE proyek (warehouse.project_id),
     * bukan proyek PO-nya: FM-10-12 mencatat apa yang tiba di lapangan hari
     * itu, dan gudang adalah lapangannya — PO menyebut siapa yang membayar,
     * bukan ke mana barang datang. GRN PO proyek ini yang diterima gudang
     * pusat bukan kedatangan site hari itu. Gudang site yang terlanjur
     * terhapus lunak tetap site proyek itu (rasional withTrashed di
     * Warehouse::project()), maka disertakan.
     */
    public function receiptsCandidates(DailyReport $dailyReport): JsonResponse
    {
        // Kunci penanda per (GRN, item), bukan per GRN: mengimpor satu baris
        // dari GRN dua-baris tidak boleh membuat baris satunya mengaku sudah
        // diambil — pengawas yang memercayai penandanya akan melewatkannya.
        $imported = $dailyReport->receipts()
            ->whereNotNull('goods_receipt_id')
            ->get(['goods_receipt_id', 'item_id'])
            ->map(fn ($row): string => $row->goods_receipt_id.':'.($row->item_id ?? ''))
            ->all();

        $rows = GoodsReceipt::query()
            ->with(['items.item', 'vendor'])
            ->where('status', StockDocumentStatus::Posted)
            ->whereDate('receipt_date', $dailyReport->report_date?->toDateString())
            ->whereHas('warehouse', fn ($query) => $query
                ->withTrashed()
                ->where('project_id', $dailyReport->project_id))
            ->orderBy('code')
            ->get()
            ->flatMap(fn (GoodsReceipt $grn) => $grn->items->map(fn ($line): array => [
                // Bentuk persis baris receipts[] yang di-POST/PUT bila dipilih…
                'goods_receipt_id' => $grn->id,
                'item_id' => $line->item_id,
                'description' => $line->item?->name ?? ('Material #'.$line->item_id),
                'qty_received' => (float) $line->qty,
                'qty_rejected' => 0,
                'unit' => $line->item?->unit,
                // …plus konteks untuk memilih dengan mata terbuka.
                'grn_code' => $grn->code,
                'item_code' => $line->item?->code,
                'delivery_note_no' => $grn->delivery_note_no,
                'vendor_name' => $grn->vendor?->name,
                'already_imported' => in_array($grn->id.':'.($line->item_id ?? ''), $imported, true),
            ]))
            ->values();

        return $this->ok($rows);
    }

    public function update(DailyReportUpdateRequest $request, DailyReport $dailyReport): JsonResponse
    {
        try {
            $report = $this->service->update($dailyReport, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(DailyReportResource::make($report));
    }

    public function destroy(DailyReport $dailyReport): JsonResponse
    {
        // Same reason as store/update: the lock and operational guards throw
        // here, and "Server Error" tells the mandor nothing about the BAST
        // that froze the report.
        try {
            $this->service->delete($dailyReport);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Daily report deleted.');
    }
}
