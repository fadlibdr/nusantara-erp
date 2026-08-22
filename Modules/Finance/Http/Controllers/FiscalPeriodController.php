<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Services\PeriodCloseService;

/**
 * Kalender fiskal dan tutup buku. All of the thinking is in
 * PeriodCloseService; this is transport.
 *
 * The list and the checklist are deliberately two endpoints. The checklist runs
 * a bank reconciliation per active account, a trial balance and a tax-export
 * overview, which makes it the heaviest read in the Finance module — so it is
 * fired by an explicit click on ONE period, never for a whole year at once.
 */
class FiscalPeriodController extends ApiController
{
    public function __construct(private readonly PeriodCloseService $service) {}

    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: now()->year;

        $periods = FiscalPeriod::query()
            ->with('closedBy:id,name')
            ->forYear($year)
            ->get()
            ->map(fn (FiscalPeriod $period): array => $this->row($period))
            ->values()
            ->all();

        return $this->ok([
            'years' => FiscalPeriod::query()->distinct()->orderBy('year')->pluck('year')->all(),
            'year' => $year,
            'periods' => $periods,
        ]);
    }

    public function checklist(FiscalPeriod $fiscalPeriod): JsonResponse
    {
        return $this->ok($this->service->payload($fiscalPeriod));
    }

    public function close(Request $request, FiscalPeriod $fiscalPeriod): JsonResponse
    {
        $data = $request->validate([
            // The min-length rule lives in the service, not here: it only
            // applies when a warning is actually being overridden, and that is
            // decided by the recomputed checklist rather than by the request.
            'note' => ['nullable', 'string', 'max:1000'],
            'acknowledge' => ['nullable', 'array'],
            'acknowledge.*' => ['string', 'max:60'],
        ], [
            'note.max' => 'Alasan terlalu panjang — maksimal 1000 karakter.',
        ]);

        try {
            $period = $this->service->close(
                $fiscalPeriod,
                $request->user(),
                $data['note'] ?? null,
                $data['acknowledge'] ?? [],
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($this->service->payload($period), "Periode {$period->label()} ditutup.");
    }

    public function reopen(Request $request, FiscalPeriod $fiscalPeriod): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => 'Alasan membuka periode wajib diisi — ini tercatat permanen.',
        ]);

        try {
            $period = $this->service->reopen($fiscalPeriod, $request->user(), $data['note']);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok($this->service->payload($period), "Periode {$period->label()} dibuka kembali.");
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:'.(now()->year + 2)],
        ], [
            'year.max' => 'Kalender fiskal dibuat paling jauh 2 tahun dari sekarang.',
            'year.min' => 'Kalender fiskal dimulai dari tahun 2000.',
        ]);

        try {
            $result = $this->service->generateYear((int) $data['year']);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created($result, $result['message']);
    }

    /**
     * The cheap row: calendar plus close state, one query plus a users join.
     * Everything expensive is behind checklist().
     */
    private function row(FiscalPeriod $period): array
    {
        return [
            'id' => $period->id,
            'year' => $period->year,
            'month' => $period->month,
            'code' => $period->code(),
            'label' => $period->label(),
            'period_start' => $period->periodStart(),
            'period_end' => $period->periodEnd(),
            'status' => $period->status->value,
            'status_label' => $period->status->label(),
            'is_closed' => $period->isClosed(),
            'is_current' => $period->isCurrent(),
            'has_ended' => $period->hasEnded(),
            'closed_at' => $period->closed_at?->toIso8601String(),
            'closed_by' => $period->closedBy === null
                ? null
                : ['id' => $period->closedBy->id, 'name' => $period->closedBy->name],
        ];
    }
}
