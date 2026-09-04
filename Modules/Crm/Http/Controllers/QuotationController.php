<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\ContractFromQuotationRequest;
use Modules\Crm\Http\Requests\QuotationStoreRequest;
use Modules\Crm\Http\Requests\QuotationUpdateRequest;
use Modules\Crm\Http\Resources\ContractResource;
use Modules\Crm\Http\Resources\QuotationResource;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Services\ContractService;
use Modules\Crm\Services\QuotationService;

class QuotationController extends ApiController
{
    public function __construct(private readonly QuotationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Quotation::query()
            ->with('customer')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('scope_type'), fn ($query) => $query->where('scope_type', $request->string('scope_type')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->orderByDesc('id');

        // The document date is created_at: the schema's only other date,
        // valid_until, answers "which offers lapse", not "penawaran November".
        return $this->listing($request, $query, QuotationResource::class,
            sortable: ['code', 'title', 'valid_until', 'total', 'status'], dateColumn: 'created_at');
    }

    public function store(QuotationStoreRequest $request): JsonResponse
    {
        $quotation = $this->service->create($request->validated());

        return $this->created(QuotationResource::make($quotation));
    }

    public function show(Quotation $quotation): JsonResponse
    {
        // methodLibraryEntry ikut dimuat: Resource meratakan kode dan judulnya,
        // dan relasi yang tidak disebut di sini membuat kunci ratanya hilang
        // dari justru satu-satunya endpoint yang menyemai form edit.
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(QuotationResource::make(
            $quotation->load('items', 'customer', 'lead', 'contract', 'methodLibraryEntry', 'approvals.user')
        ));
    }

    public function update(QuotationUpdateRequest $request, Quotation $quotation): JsonResponse
    {
        try {
            $quotation = $this->service->update($quotation, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(QuotationResource::make($quotation));
    }

    public function destroy(Quotation $quotation): JsonResponse
    {
        try {
            $this->service->delete($quotation);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Quotation deleted.');
    }

    public function submit(Request $request, Quotation $quotation): JsonResponse
    {
        try {
            $quotation->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(QuotationResource::make($quotation), 'Quotation submitted.');
    }

    public function approve(Request $request, Quotation $quotation): JsonResponse
    {
        try {
            $quotation->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(QuotationResource::make($quotation), 'Quotation approved.');
    }

    public function reject(Request $request, Quotation $quotation): JsonResponse
    {
        try {
            $quotation->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(QuotationResource::make($quotation), 'Quotation rejected.');
    }

    public function markWon(Quotation $quotation): JsonResponse
    {
        try {
            $contract = $this->service->markWon($quotation);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ContractResource::make($contract), 'Quotation won; draft contract created.');
    }

    /**
     * "Buat kontrak" / "Lengkapi kontrak" from a won quotation (T3.6).
     *
     * 201 when a contract is minted, 200 when the shell Tandai Menang left
     * behind is completed under its own number — the SPA lands on the
     * contract either way; the status tells an API caller which happened.
     * The message names both documents: the contract is the subject, the
     * quotation is where it came from.
     */
    public function createContract(ContractFromQuotationRequest $request, Quotation $quotation, ContractService $contracts): JsonResponse
    {
        try {
            $contract = $contracts->createFromQuotation($quotation, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        $contract->load('termins', 'customer', 'quotation');

        return $contract->wasRecentlyCreated
            ? $this->created(ContractResource::make($contract), "Kontrak {$contract->code} dibuat dari penawaran {$quotation->code}.")
            : $this->ok(ContractResource::make($contract), "Kontrak {$contract->code} dilengkapi dari penawaran {$quotation->code}.");
    }

    public function markLost(Request $request, Quotation $quotation): JsonResponse
    {
        $validated = $request->validate([
            'lost_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $quotation = $this->service->markLost($quotation, $validated['lost_reason'] ?? null);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(QuotationResource::make($quotation), 'Quotation marked lost.');
    }

    public function revise(Quotation $quotation): JsonResponse
    {
        try {
            $quotation = $this->service->revise($quotation);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(QuotationResource::make($quotation), "Revision {$quotation->revision} opened as draft.");
    }
}
