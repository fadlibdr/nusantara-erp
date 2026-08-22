<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\Services\PettyCashFundService;

/**
 * Imprest drawer master data, plus the two bridges into the bank:
 * replenish (draft PAY, amount pinned to float − balance) and return
 * (draft RCV). Neither endpoint moves money — they mint the payment that
 * will, and the payment walks its own approval chain.
 */
class PettyCashFundController extends ApiController
{
    public function __construct(
        private readonly PettyCashFundService $service,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PettyCashFund::query()
            ->with(['coaAccount', 'custodian:id,name'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('code');

        $page = $query->paginate($request->integer('per_page', 20));

        // A bare paginator serialises as {current_page, data: [...]} INSIDE the
        // envelope's data key, and the generic list screen reads payload.data
        // as an array — an object is truthy with no .length, so "Kas Kecil &
        // Kasbon" rendered its empty state forever and Ekspor CSV stayed
        // disabled. Same shape, same fix as RevenueRecognitionController.
        return $this->ok(
            collect($page->items())->map(fn (PettyCashFund $fund): array => $this->payload($fund))->values()->all(),
            null,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $fund = $this->service->create($data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created($this->payload($fund->load(['coaAccount', 'custodian:id,name'])));
    }

    public function show(PettyCashFund $pettyCashFund): JsonResponse
    {
        return $this->ok($this->payload($pettyCashFund->load(['coaAccount', 'custodian:id,name'])));
    }

    public function update(Request $request, PettyCashFund $pettyCashFund): JsonResponse
    {
        $data = $this->validated($request, updating: true);

        try {
            $fund = $this->service->update($pettyCashFund, $data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($this->payload($fund->load(['coaAccount', 'custodian:id,name'])));
    }

    public function destroy(PettyCashFund $pettyCashFund): JsonResponse
    {
        try {
            $this->service->delete($pettyCashFund);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Kas kecil dihapus.');
    }

    /**
     * Mint the draft PAY that tops the drawer back to its float. The amount is
     * computed HERE (float − balance) so the screen cannot invent one, and it
     * will be verified again at submit and at post.
     */
    public function replenish(Request $request, PettyCashFund $pettyCashFund): JsonResponse
    {
        $data = $request->validate(['bank_account_id' => ['required', 'integer', 'exists:fin_bank_accounts,id']]);

        $due = $this->service->replenishmentDue($pettyCashFund);

        if ((int) round($due * 100) <= 0) {
            $balance = $this->service->balance($pettyCashFund);

            return $this->error(
                "Kas kecil {$pettyCashFund->code} sudah memegang {$balance} dari float "
                ."{$pettyCashFund->float_amount}; tidak ada yang perlu diisi ulang."
            );
        }

        try {
            $payment = $this->payments->create([
                'direction' => 'out',
                'payment_date' => now()->toDateString(),
                'bank_account_id' => (int) $data['bank_account_id'],
                'amount' => $due,
                'notes' => "Isi ulang kas kecil {$pettyCashFund->code} — {$pettyCashFund->name}",
                'petty_cash_fund_id' => (int) $pettyCashFund->id,
            ]);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(
            $payment->load('bankAccount'),
            "Pembayaran isi ulang {$payment->code} dibuat sebagai draf — ajukan untuk persetujuan."
        );
    }

    /**
     * Drawer -> bank (shrinking or closing a fund): a draft RCV, posted from
     * draft like every receipt because the bank statement corroborates it.
     */
    public function returnToBank(Request $request, PettyCashFund $pettyCashFund): JsonResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:fin_bank_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $balance = $this->service->balance($pettyCashFund);
        $amount = round((float) $data['amount'], 2);

        if ((int) round(($amount - $balance) * 100) > 1) {
            return $this->error(
                "Setoran kas kecil {$pettyCashFund->code} ke bank sebesar {$amount} melebihi saldo laci ({$balance})."
            );
        }

        try {
            $payment = $this->payments->create([
                'direction' => 'in',
                'payment_date' => now()->toDateString(),
                'bank_account_id' => (int) $data['bank_account_id'],
                'amount' => $amount,
                'notes' => "Setoran kas kecil {$pettyCashFund->code} ke bank",
                'petty_cash_fund_id' => (int) $pettyCashFund->id,
            ]);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(
            $payment->load('bankAccount'),
            "Penerimaan {$payment->code} dibuat sebagai draf — posting untuk membukukan setorannya."
        );
    }

    /**
     * The fund row plus the numbers every screen needs: drawer balance, bons
     * awaiting reimbursement, advances still in employees' pockets,
     * settled-kasbon spend awaiting reimbursement — and the imprest identity
     * itself (imprest_expected), computed by the SERVICE so the cashier
     * screen compares instead of re-deriving a formula of its own (the
     * screen's shorter float − bon − kasbon rang a permanent false alarm
     * after every settled kasbon).
     */
    private function payload(PettyCashFund $fund): array
    {
        $balance = $this->service->balance($fund);

        return array_merge($fund->toArray(), [
            'balance' => $balance,
            // Asked of the SERVICE, not re-derived here: a fourth copy of
            // "float − balance" on this line is how the screen came to offer a
            // top-up that reimbursed an outstanding kasbon while the submit
            // guard would have refused it.
            'replenishment_due' => $this->service->replenishmentDue($fund),
            'unreplenished_voucher_total' => $this->service->unreplenishedVoucherTotal($fund),
            'outstanding_kasbon_total' => $this->service->outstandingKasbonTotal($fund),
            'settled_kasbon_spend_total' => $this->service->settledKasbonSpendTotal($fund),
            'imprest_expected' => $this->service->imprestExpectation($fund),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $sometimes = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$sometimes, 'string', 'max:40'],
            'name' => [$sometimes, 'string', 'max:100'],
            'coa_account_id' => [$sometimes, 'integer'],
            'custodian_id' => [$sometimes, 'integer', 'exists:users,id'],
            'project_id' => ['nullable', 'integer'],
            'float_amount' => [$sometimes, 'numeric'],
            'max_voucher_amount' => ['nullable', 'numeric', 'min:0.01'],
            'max_kasbon_amount' => ['nullable', 'numeric', 'min:0.01'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
