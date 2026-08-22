<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\BankStatementParseRequest;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Models\BankStatementLine;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\Payment;
use Modules\Finance\Services\BankStatementImportService;
use Modules\Finance\Services\BankStatementMatchService;

/**
 * Import bank statements and match their lines against what the ERP posted.
 *
 * Every domain refusal here is a LogicException carrying an Indonesian message
 * meant for the operator — bootstrap/app.php registers no exception renderer, so
 * an uncaught one is a 500 with no explanation. Catching it is the contract.
 */
class BankStatementController extends ApiController
{
    public function __construct(
        private readonly BankStatementImportService $imports,
        private readonly BankStatementMatchService $matches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = BankStatement::query()
            ->with('bankAccount')
            ->withCount([
                'lines',
                'lines as matched_lines_count' => fn ($query) => $query->where('match_status', 'matched'),
            ])
            ->when($request->filled('bank_account_id'), fn ($q) => $q->where('bank_account_id', $request->integer('bank_account_id')))
            ->orderByDesc('period_end');

        // Rekening koran tidak punya satu tanggal dokumen — periodenya yang
        // punya ujung; period_end pula yang mengurutkan register, jadi jendela
        // tanggal memakainya. Tanpa whitelist: layar rekonsiliasi bukan daftar
        // generik, tidak ada header yang bisa memakai sort.
        return $this->listing($request, $query, dateColumn: 'period_end', perPageDefault: 25);
    }

    public function show(BankStatement $bankStatement): JsonResponse
    {
        $bankStatement->load(['bankAccount', 'lines']);

        $this->attachCounterpartCodes($bankStatement);

        return $this->ok($bankStatement);
    }

    /**
     * Resolve each matched line's counterpart to its document code, in two
     * queries for the whole statement.
     *
     * Without this the screen can only show "Pembayaran #2" — an internal row
     * id, which is not a thing the operator has ever seen and cannot check
     * against anything. A reconciliation is a document people sign; it names
     * documents.
     */
    private function attachCounterpartCodes(BankStatement $statement): void
    {
        $matched = $statement->lines->filter(fn (BankStatementLine $line): bool => $line->matched_id !== null);

        $payments = Payment::query()
            ->whereIn('id', $matched->where('matched_type', BankStatementLine::MATCH_PAYMENT)->pluck('matched_id'))
            ->pluck('code', 'id');

        $journals = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->whereIn('fin_journal_lines.id', $matched->where('matched_type', BankStatementLine::MATCH_JOURNAL_LINE)->pluck('matched_id'))
            ->pluck('fin_journals.code', 'fin_journal_lines.id');

        foreach ($statement->lines as $line) {
            $line->setAttribute('matched_code', match ($line->matched_type) {
                BankStatementLine::MATCH_PAYMENT => $payments[$line->matched_id] ?? null,
                BankStatementLine::MATCH_JOURNAL_LINE => $journals[$line->matched_id] ?? null,
                default => null,
            });
        }
    }

    public function preview(BankStatementParseRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->imports->preview(
                BankAccount::query()->findOrFail($request->integer('bank_account_id')),
                (string) $request->input('format'),
                (string) $request->input('content'),
                $request->mapping(),
            ));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(BankStatementParseRequest $request): JsonResponse
    {
        try {
            $statement = $this->imports->import(
                BankAccount::query()->findOrFail($request->integer('bank_account_id')),
                (string) $request->input('format'),
                (string) $request->input('content'),
                $request->mapping(),
                $request->user()?->id,
            );

            return $this->created(
                $statement->load(['bankAccount', 'lines']),
                "Rekening koran {$statement->code} diimpor ({$statement->line_count} mutasi)."
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy(BankStatement $bankStatement): JsonResponse
    {
        try {
            $this->imports->delete($bankStatement);

            return $this->ok(null, 'Rekening koran dihapus.');
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function suggestions(BankStatement $bankStatement): JsonResponse
    {
        return $this->ok($this->matches->suggestForStatement($bankStatement));
    }

    public function lineSuggestions(BankStatementLine $bankStatementLine): JsonResponse
    {
        return $this->ok($this->matches->suggestForLine($bankStatementLine));
    }

    public function match(Request $request, BankStatementLine $bankStatementLine): JsonResponse
    {
        $data = $request->validate([
            'matched_type' => ['required', Rule::in([BankStatementLine::MATCH_PAYMENT, BankStatementLine::MATCH_JOURNAL_LINE])],
            'matched_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            return $this->ok(
                $this->matches->match($bankStatementLine, $data['matched_type'], (int) $data['matched_id'], $request->user()?->id),
                'Baris dicocokkan.'
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function unmatch(BankStatementLine $bankStatementLine): JsonResponse
    {
        try {
            return $this->ok($this->matches->unmatch($bankStatementLine), 'Pencocokan dibatalkan.');
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Marks a line as reviewed-and-unmatchable. It KEEPS counting as a
     * reconciling difference — the reason is disclosure, not disposal.
     */
    public function noMatch(Request $request, BankStatementLine $bankStatementLine): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(BankStatementLine::REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            return $this->ok(
                $this->matches->markNoMatch($bankStatementLine, $data['reason'], $data['note'] ?? null),
                'Baris ditandai tanpa padanan.'
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function reopen(BankStatementLine $bankStatementLine): JsonResponse
    {
        try {
            return $this->ok($this->matches->reopen($bankStatementLine), 'Baris dibuka kembali.');
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }
}
