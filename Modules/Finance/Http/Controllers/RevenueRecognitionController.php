<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Models\RevenueRecognitionRun;
use Modules\Finance\Services\RevenueRecognitionService;

/**
 * Pengakuan pendapatan PSAK 115. The heavy thinking lives in the service and in
 * docs/KEBIJAKAN-PENDAPATAN.md; this is transport.
 */
class RevenueRecognitionController extends ApiController
{
    public function __construct(private readonly RevenueRecognitionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $runs = RevenueRecognitionRun::query()
            ->withCount('lines')
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->paginate($request->integer('per_page', 20));

        // A bare paginator serialises as {current_page, data: [...]} INSIDE the
        // envelope's data key, which the generic list screen reads as zero
        // rows. Flatten to the house shape: data = array, meta = pagination.
        return $this->ok(
            collect($runs->items())->map(fn ($run) => $this->runPayload($run))->values()->all(),
            null,
            [
                'current_page' => $runs->currentPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
                'last_page' => $runs->lastPage(),
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $run = $this->service->calculate((int) $data['period_year'], (int) $data['period_month'], $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created($this->runPayload($run, true));
    }

    public function show(RevenueRecognitionRun $revenueRecognitionRun): JsonResponse
    {
        $revenueRecognitionRun->load(['lines.contract'])->loadCount('lines');

        return $this->ok($this->runPayload($revenueRecognitionRun, true));
    }

    /** Recalculate the draft, optionally pinning one contract's EAC. */
    public function recalculate(Request $request, RevenueRecognitionRun $revenueRecognitionRun): JsonResponse
    {
        $data = $request->validate([
            'eac_overrides' => ['nullable', 'array'],
            'eac_overrides.*' => ['numeric', 'min:1'],
        ]);

        $overrides = [];

        foreach ($data['eac_overrides'] ?? [] as $contractId => $eac) {
            $overrides[(int) $contractId] = (float) $eac;
        }

        try {
            $run = $this->service->calculate(
                $revenueRecognitionRun->period_year,
                $revenueRecognitionRun->period_month,
                $request->user(),
                $overrides,
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($this->runPayload($run, true), 'Dihitung ulang.');
    }

    public function post(Request $request, RevenueRecognitionRun $revenueRecognitionRun): JsonResponse
    {
        try {
            $run = $this->service->post($revenueRecognitionRun, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($this->runPayload($run->load('lines.contract'), true), 'Jurnal pengakuan pendapatan diposting.');
    }

    public function destroy(RevenueRecognitionRun $revenueRecognitionRun): JsonResponse
    {
        try {
            $this->service->delete($revenueRecognitionRun);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Run dihapus.');
    }

    private function runPayload(RevenueRecognitionRun $run, bool $withLines = false): array
    {
        $payload = [
            'id' => $run->id,
            'code' => $run->code,
            'period_year' => $run->period_year,
            'period_month' => $run->period_month,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'total_adjustment' => $run->total_adjustment,
            'lines_count' => $run->lines_count ?? $run->lines()->count(),
            'posted_at' => $run->posted_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];

        if ($withLines) {
            $payload['lines'] = $run->lines->map(fn ($line) => [
                'id' => $line->id,
                'contract_id' => $line->contract_id,
                'contract_code' => $line->contract?->code,
                'contract_title' => $line->contract?->title,
                'project_id' => $line->project_id,
                'scope_type' => $line->scope_type,
                'revenue_account' => $line->revenue_account,
                'transaction_price' => $line->transaction_price,
                'estimated_total_cost' => $line->estimated_total_cost,
                'eac_source' => $line->eac_source,
                'cost_to_date' => $line->cost_to_date,
                'progress_pct' => $line->progress_pct,
                'revenue_cumulative' => $line->revenue_cumulative,
                'billed_cumulative' => $line->billed_cumulative,
                'contract_balance' => $line->contract_balance,
                'provision_balance' => $line->provision_balance,
                'revenue_adjustment' => $line->revenue_adjustment,
                'provision_adjustment' => $line->provision_adjustment,
                // PSAK 115 para 120 disclosure: sisa kewajiban pelaksanaan.
                'remaining_performance_obligation' => round((float) $line->transaction_price - (float) $line->revenue_cumulative, 2),
            ])->values()->all();
        }

        return $payload;
    }
}
