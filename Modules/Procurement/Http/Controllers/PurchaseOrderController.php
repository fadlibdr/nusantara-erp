<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\PurchaseOrderStoreRequest;
use Modules\Procurement\Http\Requests\PurchaseOrderUpdateRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Services\BudgetGateService;
use Modules\Procurement\Services\PoService;
use Modules\Procurement\Services\PriceDeviationService;
use Modules\Procurement\Services\VendorEvaluationService;
use Modules\Procurement\Services\VendorQualificationService;

class PurchaseOrderController extends ApiController
{
    public function __construct(
        private readonly PoService $service,
        private readonly VendorQualificationService $qualification,
        private readonly VendorEvaluationService $evaluations,
        private readonly PriceDeviationService $prices,
        private readonly BudgetGateService $budget,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()
            ->with('vendor')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, PurchaseOrderResource::class,
            sortable: ['code', 'order_date', 'total', 'status'], dateColumn: 'order_date');
    }

    public function store(PurchaseOrderStoreRequest $request): JsonResponse
    {
        try {
            $po = $this->service->create($request->validated());
        } catch (LogicException $e) {
            // Gate prakualifikasi vendor di PoService::create menolak dengan
            // LogicException; tanpa catch ini penolakan berbahasa Indonesia
            // itu pecah jadi 500 tanpa pesan.
            return $this->error($e->getMessage());
        }

        return $this->created(PurchaseOrderResource::make($po));
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->ok(PurchaseOrderResource::make(
            $purchaseOrder->load('items', 'vendor', 'purchaseRequisition')
        ));
    }

    public function update(PurchaseOrderUpdateRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $po = $this->service->update($purchaseOrder, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseOrderResource::make($po));
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $this->service->delete($purchaseOrder);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'PO deleted.');
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            // Gate prakualifikasi (temuan #35) berdiri DI SINI, pada saat
            // mengajukan, bukan hanya saat membuat draf: dokumen wajib bisa
            // kedaluwarsa — dan vendor bisa dinonaktifkan — di antara draf
            // dan pengajuan. Draf tetap bebas dibuat selama vendornya sehat;
            // yang ditolak adalah menjadikannya komitmen.
            $vendor = $purchaseOrder->vendor;

            if ($vendor === null) {
                return $this->error("Vendor PO {$purchaseOrder->code} sudah dihapus; pilih vendor lain sebelum mengajukan.");
            }

            $reason = trim((string) $request->input('qualification_override_reason', ''));
            $overridden = $this->qualification->assertQualified($vendor, $reason === '' ? null : $reason);

            $purchaseOrder = DB::transaction(function () use ($purchaseOrder, $request, $reason, $overridden): PurchaseOrder {
                // Kedua gate di bawah memutus berdasarkan BARIS dan TOTAL
                // dokumen, jadi keputusannya diambil pada re-read terkunci di
                // dalam transaksi — instance hasil route-binding bisa sudah
                // basi terhadap edit paralel, dan gate yang menilai baris
                // lama meloloskan (atau menuduh) harga yang tidak pernah
                // diajukan.
                /** @var PurchaseOrder $po */
                $po = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

                // Kendali harga (#34 tahap 2) SEBELUM gate anggaran (#33):
                // masing-masing melempar 422 dengan kunci galatnya sendiri,
                // dan SPA mengonfirmasi satu jenis peringatan per putaran —
                // kunci campuran dalam satu jawaban tidak pernah cocok pola.
                $this->prices->assertConfirmedIfDeviant($po, $request->boolean('confirm_price_deviation'));
                $this->budget->assertPoWithinBudget($po, $request->boolean('confirm_over_budget'));

                // submit() DULU, alasan sesudahnya — dan keduanya satu
                // transaksi. Urutan lama mencap alasan sebelum Approvable
                // sempat menolak, jadi pengajuan ulang PO yang sudah
                // submitted (ditolak 422) tetap meninggalkan jejak override
                // palsu di dokumen yang tidak pernah melewati gate.
                $po->submit($request->user());

                if ($overridden !== []) {
                    // Alasan hanya tercatat saat override benar-benar
                    // DIPAKAI — alasan yang diketik untuk vendor sehat
                    // bukan jejak audit.
                    $po->forceFill(['qualification_override_reason' => $reason])->save();
                }

                return $po;
            });
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        $message = $purchaseOrder->needs_director_approval
            ? 'PO submitted; requires director approval (above threshold).'
            : 'PO submitted.';

        return $this->ok(PurchaseOrderResource::make($purchaseOrder), $message);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            // Through the service, not the model: PoService::approve is where
            // the needs_director_approval gate lives.
            $this->service->approve($purchaseOrder, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseOrderResource::make($purchaseOrder), 'PO approved.');
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $purchaseOrder->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseOrderResource::make($purchaseOrder), 'PO rejected.');
    }

    public function close(PurchaseOrder $purchaseOrder): JsonResponse
    {
        try {
            $po = $this->service->close($purchaseOrder);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        // Temuan #68: menutup PO besar adalah MOMEN evaluasi — sesudahnya
        // tidak ada peristiwa lain yang mengingatkan siapa pun.
        $prompt = $this->evaluations->promptEvaluationIfDue($po);

        return $this->ok(
            PurchaseOrderResource::make($po),
            $prompt === null ? 'PO closed.' : "PO closed. {$prompt}",
        );
    }
}
