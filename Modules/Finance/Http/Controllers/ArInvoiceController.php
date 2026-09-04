<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\ArInvoiceStoreRequest;
use Modules\Finance\Http\Requests\ArInvoiceUpdateRequest;
use Modules\Finance\Http\Requests\DocumentCancelRequest;
use Modules\Finance\Http\Requests\FakturPajakRequest;
use Modules\Finance\Http\Resources\ArInvoiceResource;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Services\ArInvoiceService;

class ArInvoiceController extends ApiController
{
    public function __construct(private readonly ArInvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ArInvoice::query()
            ->with(['customer', 'contract'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%")
                        ->orWhere('faktur_pajak_no', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('contract_id'), fn ($query) => $query->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            // Dokumen dibatalkan selalu lolos amount_paid < total (pembatalan
            // mensyaratkan nol dibayar), jadi tanpa filter ini "unpaid" justru
            // mengembalikan piutang yang jurnalnya sudah dibalik.
            ->when($request->boolean('unpaid'), fn ($query) => $query
                ->whereColumn('amount_paid', '<', 'total')
                ->whereNot('status', DocumentStatus::Cancelled->value))
            ->orderByDesc('id');

        // 'outstanding' dan 'amount_paid' sengaja tidak masuk whitelist:
        // 'outstanding' kolom hitungan (bukan kolom tabel), 'amount_paid' tidak
        // dirender daftar — kunci yang diiklankan tanpa kolom layar = tombol mati.
        return $this->listing($request, $query, ArInvoiceResource::class,
            sortable: ['code', 'invoice_date', 'due_date', 'total', 'status'], dateColumn: 'invoice_date');
    }

    public function store(ArInvoiceStoreRequest $request): JsonResponse
    {
        try {
            $invoice = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ArInvoiceResource::make($invoice->load(['customer', 'contract', 'termin'])));
    }

    public function show(ArInvoice $arInvoice): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(ArInvoiceResource::make(
            $arInvoice->load(['customer', 'contract', 'termin', 'retentions', 'approvals.user'])
        ));
    }

    public function update(ArInvoiceUpdateRequest $request, ArInvoice $arInvoice): JsonResponse
    {
        try {
            $invoice = $this->service->update($arInvoice, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ArInvoiceResource::make($invoice->load(['customer', 'contract', 'termin'])));
    }

    public function destroy(ArInvoice $arInvoice): JsonResponse
    {
        try {
            $this->service->delete($arInvoice);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Invoice deleted.');
    }

    public function submit(Request $request, ArInvoice $arInvoice): JsonResponse
    {
        try {
            $arInvoice->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ArInvoiceResource::make($arInvoice), 'Invoice submitted.');
    }

    public function approve(Request $request, ArInvoice $arInvoice): JsonResponse
    {
        try {
            $invoice = $this->service->approve($arInvoice, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            ArInvoiceResource::make($invoice->load(['customer', 'contract', 'termin', 'retentions'])),
            'Invoice approved and journaled.'
        );
    }

    public function reject(Request $request, ArInvoice $arInvoice): JsonResponse
    {
        try {
            $arInvoice->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ArInvoiceResource::make($arInvoice), 'Invoice rejected.');
    }

    /**
     * Membatalkan invoice yang sudah disetujui: jurnal balik, termin dibuka
     * kembali, retensi dilepas.
     */
    public function cancel(DocumentCancelRequest $request, ArInvoice $arInvoice): JsonResponse
    {
        try {
            $invoice = $this->service->cancel($arInvoice, $request->user(), $request->validated('reason'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            ArInvoiceResource::make($invoice->load(['customer', 'contract', 'termin'])),
            "Invoice {$invoice->code} dibatalkan; jurnal pembalik sudah diposting."
        );
    }

    public function faktur(FakturPajakRequest $request, ArInvoice $arInvoice): JsonResponse
    {
        try {
            $invoice = $this->service->registerFakturPajak($arInvoice, $request->validated('faktur_pajak_no'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ArInvoiceResource::make($invoice), 'Faktur pajak registered.');
    }
}
