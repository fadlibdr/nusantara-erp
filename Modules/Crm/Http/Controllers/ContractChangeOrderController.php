<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Crm\Enums\ChangeOrderType;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractChangeOrder;
use Modules\Crm\Services\ContractChangeOrderService;

/**
 * Pekerjaan tambah-kurang. Same submit/approve/reject lifecycle as every other
 * approvable document, so it inherits the approval notifications too.
 */
class ContractChangeOrderController extends ApiController
{
    public function __construct(private readonly ContractChangeOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $orders = ContractChangeOrder::query()
            ->with('contract:id,code,title')
            ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('change_type'), fn ($q) => $q->where('change_type', $request->string('change_type')))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($query) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $query->where('code', 'like', $term)->orWhere('title', 'like', $term);
            }))
            ->orderByDesc('change_date');

        // No Resource class here — listing() emits the rows as a flat array
        // (the raw-paginator shape it replaced nested them a level deeper
        // than list.js reads, so this screen showed "Belum ada" over data).
        // The old 100-row cap is gone on purpose: the concern leaves per_page
        // uncapped so pickers can page at 500.
        return $this->listing($request, $orders, null,
            sortable: ['code', 'title', 'change_date', 'value_change', 'status'], dateColumn: 'change_date');
    }

    public function show(ContractChangeOrder $contractChangeOrder): JsonResponse
    {
        return $this->ok($contractChangeOrder->load(['contract', 'approvals.user:id,name']));
    }

    /** What the contract is worth now, and how it got there. */
    public function summary(Contract $contract): JsonResponse
    {
        return $this->ok($this->service->summaryFor($contract));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);

        try {
            return $this->created($this->service->create($data), 'Perubahan pekerjaan dibuat.');
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update(Request $request, ContractChangeOrder $contractChangeOrder): JsonResponse
    {
        try {
            return $this->ok($this->service->update($contractChangeOrder, $this->validated($request, false)));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(ContractChangeOrder $contractChangeOrder): JsonResponse
    {
        try {
            $this->service->delete($contractChangeOrder);

            return $this->ok(null, 'Perubahan pekerjaan dihapus.');
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function submit(Request $request, ContractChangeOrder $contractChangeOrder): JsonResponse
    {
        try {
            $contractChangeOrder->submit($request->user());

            return $this->ok($contractChangeOrder->refresh(), 'Diajukan untuk persetujuan.');
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function approve(Request $request, ContractChangeOrder $contractChangeOrder): JsonResponse
    {
        try {
            $approved = $this->service->approve($contractChangeOrder, $request->user(), $request->input('note'));

            return $this->ok($approved, "Disetujui — nilai kontrak diperbarui menjadi {$approved->contract->value}.");
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Wizard pasca-persetujuan (temuan #14): jadwalkan penagihan nilai tambah
     * sebagai satu termin baru senilai value_change. due_date wajib — tanpa
     * tanggal, termin itu tidak akan pernah muncul di antrean siap tagih, dan
     * antrean itulah alasan menjadwalkan alih-alih menagih manual.
     */
    public function scheduleTermin(Request $request, ContractChangeOrder $contractChangeOrder): JsonResponse
    {
        $data = $request->validate([
            'due_date' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $termin = $this->service->scheduleTermin($contractChangeOrder, $data);

            return $this->created(
                $termin,
                "Termin {$termin->termin_no} senilai {$termin->amount} dijadwalkan — masuk antrean siap tagih per {$termin->due_date->toDateString()}."
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function reject(Request $request, ContractChangeOrder $contractChangeOrder): JsonResponse
    {
        try {
            return $this->ok(
                $this->service->reject($contractChangeOrder, $request->user(), $request->input('note')),
                'Ditolak.',
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'contract_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('crm_contracts', 'id')],
            'change_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'reason' => ['nullable', Rule::in(['permintaan_pelanggan', 'kondisi_lapangan', 'desain', 'lainnya'])],
            // Absent means tambah_kurang (the column's default) so older
            // clients that never send this field keep meaning what they meant.
            'change_type' => ['sometimes', 'required', Rule::enum(ChangeOrderType::class)],
            // Signed, and never zero — a change order that changes nothing is a
            // note, not an amendment.
            'value_change' => [$creating ? 'required' : 'sometimes', 'numeric', 'not_in:0'],
            'customer_ref' => ['nullable', 'string', 'max:60'],
        ]);
    }
}
