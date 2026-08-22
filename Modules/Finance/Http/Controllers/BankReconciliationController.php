<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Services\BankReconciliationService;

/**
 * The bank reconciliation report. Read-only in the strongest sense: it does not
 * post, and it does not gate on fiscal periods either — reconciling a closed
 * month is the normal case, and the report is a statement about what was
 * posted, not a posting.
 */
class BankReconciliationController extends ApiController
{
    public function __construct(private readonly BankReconciliationService $reconciliation) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:fin_bank_accounts,id'],
            'as_of' => ['nullable', 'date'],
        ]);

        try {
            return $this->ok($this->reconciliation->reconcile(
                BankAccount::query()->with('coaAccount')->findOrFail($data['bank_account_id']),
                $data['as_of'] ?? null,
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function overview(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date']]);

        return $this->ok($this->reconciliation->overview($data['as_of'] ?? null));
    }
}
