<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Enums\KasbonStatus;
use Modules\Finance\Enums\PettyCashCategory;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Services\KasbonService;

class KasbonController extends ApiController
{
    public function __construct(private readonly KasbonService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Kasbon::query()
            ->with(['fund:id,code,name,custodian_id'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")->orWhere('purpose', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('fund_id'), fn ($query) => $query->where('fund_id', $request->integer('fund_id')))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            // ?overdue=1 — issued past its due date: the site rule's teeth.
            ->when($request->boolean('overdue'), fn ($query) => $query
                ->where('status', KasbonStatus::Issued->value)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString()))
            ->orderByDesc('advance_date')
            ->orderByDesc('id');

        // Jendela tanggal pada advance_date: register kasbon dibaca menurut
        // kapan uang keluar; pertanyaan jatuh tempo sudah dijawab ?overdue=1.
        // Layar kas kecil bukan daftar generik, jadi tidak ada whitelist sort.
        return $this->listing($request, $query, dateColumn: 'advance_date');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $kasbon = $this->service->create($data, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created($kasbon->load('fund:id,code,name,custodian_id'));
    }

    public function show(Kasbon $kasbon): JsonResponse
    {
        return $this->ok($kasbon->load(['fund:id,code,name,custodian_id', 'lines', 'creator:id,name']));
    }

    public function update(Request $request, Kasbon $kasbon): JsonResponse
    {
        $data = $this->validated($request, updating: true);

        try {
            $kasbon = $this->service->update($kasbon, $data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($kasbon->load('fund:id,code,name,custodian_id'));
    }

    public function destroy(Kasbon $kasbon): JsonResponse
    {
        try {
            $this->service->delete($kasbon);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Kasbon dihapus.');
    }

    public function issue(Request $request, Kasbon $kasbon): JsonResponse
    {
        try {
            $kasbon = $this->service->issue($kasbon, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            $kasbon->load('fund:id,code,name,custodian_id'),
            'Kasbon dicairkan; piutang karyawan dan saldo laci sudah dibukukan.'
        );
    }

    public function settle(Request $request, Kasbon $kasbon): JsonResponse
    {
        $data = $request->validate([
            'settlement_date' => ['required', 'date'],
            // Zero lines is legal: an untouched advance returned in full.
            'lines' => ['present', 'array'],
            'lines.*.category' => ['required', Rule::enum(PettyCashCategory::class)],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.wbs_task_id' => ['nullable', 'integer'],
        ]);

        try {
            $kasbon = $this->service->settle($kasbon, $data['lines'], $data['settlement_date'], $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            $kasbon->load(['fund:id,code,name,custodian_id', 'lines']),
            'Pertanggungjawaban dibukukan; piutang karyawan lunas.'
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $sometimes = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'fund_id' => [$updating ? 'prohibited' : 'required', 'integer', 'exists:fin_petty_cash_funds,id'],
            'employee_id' => [$sometimes, 'integer'],
            'advance_date' => [$sometimes, 'date'],
            'amount' => [$sometimes, 'numeric', 'min:0.01'],
            'purpose' => [$sometimes, 'string', 'max:500'],
            'project_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
        ]);
    }
}
