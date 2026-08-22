<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\JournalStoreRequest;
use Modules\Finance\Http\Requests\JournalUpdateRequest;
use Modules\Finance\Http\Resources\JournalResource;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\JournalService;

class JournalController extends ApiController
{
    public function __construct(private readonly JournalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Journal::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('reference_type'), fn ($query) => $query->where('reference_type', $request->string('reference_type')))
            ->orderByDesc('journal_date')
            ->orderByDesc('id');

        // Jendela date_from/date_to lokal yang dulu ada di sini pindah ke
        // listing(): predikatnya identik (whereDate inklusif dua sisi), jadi
        // duplikatnya dihapus. 'description' tidak di-whitelist — teks bebas
        // panjang bukan kolom ORDER BY (lihat catatan listing()).
        return $this->listing($request, $query, JournalResource::class,
            sortable: ['code', 'journal_date', 'reference_type', 'status'], dateColumn: 'journal_date');
    }

    public function store(JournalStoreRequest $request): JsonResponse
    {
        try {
            // The maker is taken from the session, never from the body — a
            // created_by the client could choose is a maker-checker the client
            // could evade.
            $journal = $this->service->create(
                $request->validated() + ['created_by' => $request->user()?->id],
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(JournalResource::make($journal));
    }

    public function show(Journal $journal): JsonResponse
    {
        return $this->ok(JournalResource::make($journal->load('lines.account')));
    }

    public function update(JournalUpdateRequest $request, Journal $journal): JsonResponse
    {
        try {
            $journal = $this->service->update($journal, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(JournalResource::make($journal));
    }

    public function destroy(Journal $journal): JsonResponse
    {
        try {
            $this->service->delete($journal);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Journal deleted.');
    }

    public function post(Request $request, Journal $journal): JsonResponse
    {
        try {
            $journal = $this->service->post($journal, $request->user()?->id);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(JournalResource::make($journal), 'Journal posted.');
    }
}
