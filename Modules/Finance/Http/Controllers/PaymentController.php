<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\PaymentPostRequest;
use Modules\Finance\Http\Requests\PaymentStoreRequest;
use Modules\Finance\Http\Requests\PaymentSubmitRequest;
use Modules\Finance\Http\Requests\PaymentUpdateRequest;
use Modules\Finance\Http\Resources\PaymentResource;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Payment;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\Support\SettleableLiabilities;

class PaymentController extends ApiController
{
    public function __construct(private readonly PaymentService $service) {}

    /**
     * The non-AP liabilities a disbursement may settle, each with the exact
     * ceiling the submit guard will enforce for a payment dated ?date= —
     * posted balance through that month's end, minus what other unposted
     * (submitted|approved) payments have already claimed — so the screen never
     * offers a number the server will refuse.
     *
     * Accounts whose ceiling is zero or negative are still returned WITH that
     * number: a card that silently omits 2-1110 the month payroll was not
     * accrued would mystify the operator, where "saldo 0" explains itself.
     */
    public function settleableLiabilities(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $date = $data['date'] ?? now()->toDateString();

        $rows = [];

        foreach (SettleableLiabilities::codes() as $code) {
            $account = Account::query()->where('code', $code)->first();

            if ($account === null || ! $account->is_active || ! $account->is_postable) {
                continue; // a chart variant without the account has nothing to offer
            }

            $rows[] = [
                'account_id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'ceiling' => round(
                    SettleableLiabilities::ceiling($account, $date)
                    - SettleableLiabilities::pendingAllocations((int) $account->id),
                    2,
                ),
            ];
        }

        return $this->ok($rows);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()
            ->with('bankAccount')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('reference', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', $request->string('direction')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('bank_account_id'), fn ($query) => $query->where('bank_account_id', $request->integer('bank_account_id')))
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        return $this->listing($request, $query, PaymentResource::class,
            sortable: ['code', 'payment_date', 'direction', 'amount', 'reference', 'status'],
            dateColumn: 'payment_date');
    }

    public function store(PaymentStoreRequest $request): JsonResponse
    {
        try {
            $payment = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(PaymentResource::make($payment->load('bankAccount')));
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->ok(PaymentResource::make(
            $payment->load(['bankAccount', 'allocations', 'withholdings.invoice', 'approvals.user', 'pettyCashFund'])
        ));
    }

    /**
     * Ajukan pembayaran keluar untuk persetujuan, berikut alokasinya.
     *
     * fin.update rather than fin.create, matching ar-invoices/ap-bills two
     * blocks above in the route file: submitting is an act of preparation, not
     * of authorisation.
     */
    public function submit(PaymentSubmitRequest $request, Payment $payment): JsonResponse
    {
        try {
            $payment = $this->service->submit(
                $payment,
                $request->validated('allocations'),
                $request->user(),
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PaymentResource::make($payment->load(['bankAccount', 'allocations', 'approvals.user'])),
            'Pembayaran diajukan untuk persetujuan.'
        );
    }

    public function approve(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $payment = $this->service->approve($payment, $request->user(), $data['note'] ?? null);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PaymentResource::make($payment->load(['bankAccount', 'allocations', 'approvals.user'])),
            'Pembayaran disetujui dan siap diposting.'
        );
    }

    /**
     * A rejection must say why — the clerk has to know what to correct, and
     * "ditolak" on its own sends the payment back and forth forever.
     */
    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        try {
            $payment = $this->service->reject($payment, $request->user(), $data['note']);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PaymentResource::make($payment->load(['bankAccount', 'allocations', 'approvals.user'])),
            'Pembayaran ditolak dan dikembalikan ke draf.'
        );
    }

    public function update(PaymentUpdateRequest $request, Payment $payment): JsonResponse
    {
        try {
            $payment = $this->service->update($payment, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PaymentResource::make($payment->load('bankAccount')));
    }

    public function destroy(Payment $payment): JsonResponse
    {
        try {
            $this->service->delete($payment);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Payment deleted.');
    }

    /**
     * Balikkan pembayaran yang sudah diposting.
     *
     * The reason is mandatory for the same reason a cancellation's is: an
     * auditor asks "why" first, and a reversal that only says who and when
     * explains nothing. fin.post, matching ar-invoices/cancel and
     * ap-bills/cancel — reversing is a posting act, not an approval one.
     */
    public function reverse(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $payment = $this->service->reverse($payment, $request->user(), $data['reason']);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PaymentResource::make($payment->load(['bankAccount', 'allocations', 'withholdings.invoice', 'approvals.user'])),
            'Pembayaran dibalik; jurnal pembalik diposting dan dokumen yang dilunasinya dibuka kembali.'
        );
    }

    public function post(PaymentPostRequest $request, Payment $payment): JsonResponse
    {
        try {
            $payment = $this->service->post(
                $payment,
                $request->validated('allocations'),
                $request->user()?->id,
                $request->validated('withholdings') ?? [],
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PaymentResource::make($payment->load(['bankAccount', 'allocations', 'withholdings.invoice', 'approvals.user'])),
            'Payment posted; documents settled and journaled.'
        );
    }
}
