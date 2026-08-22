<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Services\CashFlowService;
use Modules\Finance\Services\GeneralLedgerService;
use Modules\Finance\Services\ReportService;

class ReportController extends ApiController
{
    public function __construct(
        private readonly ReportService $service,
        private readonly CashFlowService $cashFlows,
        private readonly GeneralLedgerService $generalLedger,
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            return $this->ok($this->service->trialBalance(
                $request->integer('year'),
                $request->integer('month'),
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'project_id' => ['nullable', 'integer'],
        ]);

        return $this->ok($this->service->profitLoss(
            $request->string('from')->toString(),
            $request->string('to')->toString(),
            $request->filled('project_id') ? $request->integer('project_id') : null,
        ));
    }

    /**
     * A balance sheet is a point-in-time snapshot, so an omitted as_of means
     * "now" — the same default the other point-in-time reports on this
     * controller already apply (arAging/apAging read Carbon::today()). Only the
     * PERIOD reports above (trialBalance, profitLoss) genuinely require their
     * dates, because there is no sensible default period.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        return $this->ok($this->service->balanceSheet(
            $request->filled('as_of')
                ? $request->string('as_of')->toString()
                : Carbon::today()->toDateString(),
        ));
    }

    /**
     * Buku besar satu akun — the drill-down behind a trial-balance row.
     *
     * A PERIOD report like profitLoss, so from/to are required: "buku besar
     * sejak awal waktu" is not a question anyone asks, and defaulting it would
     * page a bank account's whole history for someone who wanted one month.
     *
     * The pagination rides INSIDE the payload rather than in the envelope's
     * meta the way listing() does, because a page of ledger rows is not the
     * whole answer here — the opening, movement and closing figures belong to
     * the window, not to the page, and splitting them across data and meta
     * would invite a caller to read a page total as a period total.
     */
    public function generalLedger(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('fin_accounts', 'id')->whereNull('deleted_at')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            // Validated for existence, not merely for shape: a mistyped project
            // filter that silently returned an empty ledger would read as "this
            // account never touched that project", which is a different claim.
            //
            // Deliberately WITHOUT the ->whereNull('deleted_at') that account_id
            // carries one line up. The asymmetry is the point: the two rules do
            // different jobs. account_id is the report's SUBJECT — ledger()
            // resolves it through the soft-delete scope and refuses outright
            // ("Akun #{id} tidak ditemukan di bagan akun"), and every figure on
            // the screen is signed and labelled from that account row, so a
            // deleted account has no ledger to draw and the rule only turns
            // that refusal into a proper field error. project_id merely NARROWS
            // the subject: the filter lands on fin_journal_lines.project_id and
            // prj_projects is leftJoined for the code and name columns alone,
            // so a soft-deleted project still returns every one of its lines,
            // still carrying its own code.
            // Excluding it would 422 the one reader who needs it most — the
            // accountant explaining a 1-1300 balance that a closed-and-removed
            // project put there, whose lines are still posted and still counted
            // by the trial balance.
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.GeneralLedgerService::MAX_PER_PAGE],
        ]);

        try {
            return $this->ok($this->generalLedger->ledger(
                $request->integer('account_id'),
                $request->string('from')->toString(),
                $request->string('to')->toString(),
                $request->filled('project_id') ? $request->integer('project_id') : null,
                $request->filled('page') ? $request->integer('page') : 1,
                $request->filled('per_page') ? $request->integer('per_page') : null,
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function projectProfitability(Request $request, int $projectId): JsonResponse
    {
        return $this->ok($this->service->projectProfitability($projectId));
    }

    /**
     * Laporan arus kas PSAK 2 — a PERIOD report like profitLoss, so from/to
     * are required: there is no sensible default period.
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        try {
            return $this->ok($this->cashFlows->statement(
                $request->string('from')->toString(),
                $request->string('to')->toString(),
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function cashProjection(Request $request): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:7', 'max:180'],
        ]);

        try {
            return $this->ok($this->cashFlows->projection(
                $request->filled('days') ? $request->integer('days') : 90,
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function bankBalances(): JsonResponse
    {
        return $this->ok($this->cashFlows->bankBalances());
    }

    /**
     * Umur piutang/hutang per tanggal — a point-in-time report, so an omitted
     * as_of means "today", byte for byte what these endpoints returned before
     * the parameter existed.
     *
     * agingReport() has been date-bounded on both sides (the documents and the
     * money against them) since the receipt-dating fix, but nothing could reach
     * that: arAging()/apAging() took no Request, so
     * GET /api/finance/reports/ar-aging?as_of=2026-06-30 answered 200 with
     * as_of 2026-08-03 — an accountant reconciling the June aging against the
     * June balance sheet got today's figures and no error saying so. Same
     * validation and same default as balanceSheet(), because the two are read
     * side by side and have to be capable of the same date.
     */
    public function arAging(Request $request): JsonResponse
    {
        return $this->ok($this->service->agingReport('ar', $this->agingAsOf($request)));
    }

    public function apAging(Request $request): JsonResponse
    {
        return $this->ok($this->service->agingReport('ap', $this->agingAsOf($request)));
    }

    private function agingAsOf(Request $request): string
    {
        $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        return $request->filled('as_of')
            ? $request->string('as_of')->toString()
            : Carbon::today()->toDateString();
    }
}
