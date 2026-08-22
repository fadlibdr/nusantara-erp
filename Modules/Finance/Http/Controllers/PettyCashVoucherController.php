<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Enums\PettyCashCategory;
use Modules\Finance\Models\PettyCashVoucher;
use Modules\Finance\Services\PettyCashVoucherService;

class PettyCashVoucherController extends ApiController
{
    public function __construct(private readonly PettyCashVoucherService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = PettyCashVoucher::query()
            // replenishmentPayment ikut dimuat supaya layar bisa membedakan
            // "distempel isi ulang yang MASIH menunggu" dari "sudah diganti":
            // uangnya baru bergerak ketika pembayaran stempelnya terposting.
            ->with(['fund:id,code,name,custodian_id', 'replenishmentPayment:id,code,status'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('fund_id'), fn ($query) => $query->where('fund_id', $request->integer('fund_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            // ?replenished=0 — the pile the next top-up will freeze; =1 — bons
            // already covered by a replenishment payment.
            ->when($request->filled('replenished'), fn ($query) => $request->boolean('replenished')
                ? $query->whereNotNull('replenishment_payment_id')
                : $query->whereNull('replenishment_payment_id'))
            ->orderByDesc('voucher_date')
            ->orderByDesc('id');

        // voucher_date adalah tanggal dokumen bon — jendela Dari/Sampai di
        // situ. Layar kas kecil bukan daftar generik, jadi tanpa whitelist sort.
        return $this->listing($request, $query, dateColumn: 'voucher_date');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $voucher = $this->service->create($data, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created($voucher->load('fund:id,code,name,custodian_id'));
    }

    public function show(PettyCashVoucher $pettyCashVoucher): JsonResponse
    {
        return $this->ok($pettyCashVoucher->load([
            'fund:id,code,name,custodian_id',
            'replenishmentPayment:id,code,status',
            'creator:id,name',
        ]));
    }

    public function update(Request $request, PettyCashVoucher $pettyCashVoucher): JsonResponse
    {
        $data = $this->validated($request, updating: true);

        try {
            $voucher = $this->service->update($pettyCashVoucher, $data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($voucher->load('fund:id,code,name,custodian_id'));
    }

    public function destroy(PettyCashVoucher $pettyCashVoucher): JsonResponse
    {
        try {
            $this->service->delete($pettyCashVoucher);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Voucher kas kecil dihapus.');
    }

    public function post(Request $request, PettyCashVoucher $pettyCashVoucher): JsonResponse
    {
        try {
            $voucher = $this->service->post($pettyCashVoucher, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            $voucher->load('fund:id,code,name,custodian_id'),
            'Voucher diposting; beban dan saldo laci sudah dibukukan.'
        );
    }

    public function cancel(Request $request, PettyCashVoucher $pettyCashVoucher): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $voucher = $this->service->cancel($pettyCashVoucher, $request->user(), $data['reason']);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            $voucher->load('fund:id,code,name,custodian_id'),
            'Voucher dibatalkan dan jurnal pembaliknya diposting.'
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $sometimes = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'fund_id' => [$updating ? 'prohibited' : 'required', 'integer', 'exists:fin_petty_cash_funds,id'],
            'voucher_date' => [$sometimes, 'date'],
            'category' => [$sometimes, Rule::enum(PettyCashCategory::class)],
            'description' => [$sometimes, 'string', 'max:500'],
            'amount' => [$sometimes, 'numeric', 'min:0.01'],
            'project_id' => ['nullable', 'integer'],
            'wbs_task_id' => ['nullable', 'integer'],
        ]);
    }
}
