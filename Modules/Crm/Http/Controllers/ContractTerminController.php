<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\ContractTerminUpdateRequest;
use Modules\Crm\Http\Resources\ContractTerminResource;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Services\TerminBillingService;

/**
 * Termin as a billing object rather than a line of a contract.
 *
 * The queue is guarded by fin.view, not crm.view: the question it answers —
 * "what may I invoice today" — belongs to finance, and the people who own the
 * customer relationship are not the people who raise the invoice.
 */
class ContractTerminController extends ApiController
{
    public function __construct(private readonly TerminBillingService $billing) {}

    /**
     * Antrean siap tagih. Longest-waiting first, because the top of this list is
     * the money that has been sitting the longest — that is the whole report.
     */
    public function billingReady(Request $request): JsonResponse
    {
        $rows = $this->billing->billingReady(
            $request->filled('as_of') ? $request->date('as_of')->toDateString() : null,
            $request->filled('contract_id') ? $request->integer('contract_id') : null,
        );

        return $this->ok($rows, null, [
            'count' => count($rows),
            // The headline number: how much is billable right now and unbilled.
            'total_amount' => round(array_sum(array_column($rows, 'amount')), 2),
        ]);
    }

    public function update(ContractTerminUpdateRequest $request, ContractTermin $contractTermin): JsonResponse
    {
        try {
            $termin = $this->billing->reschedule($contractTermin, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ContractTerminResource::make($termin), 'Jadwal termin diperbarui.');
    }
}
