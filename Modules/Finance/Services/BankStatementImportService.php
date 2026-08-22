<?php

namespace Modules\Finance\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\BankStatementFormat;
use Modules\Finance\Enums\BankStatementMatchStatus;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Support\ParsedStatement;

/**
 * Imports a bank statement. Writes NOTHING to the general ledger — not on
 * import, not ever. A statement is evidence about postings that already exist.
 *
 * Three refusals do the real work:
 *
 *  1. THE TIE-OUT. Opening + movements must equal closing, exactly, in cents.
 *     A parser that drops a transaction produces a file that looks complete;
 *     the bank's own arithmetic is the only thing that knows otherwise.
 *
 *  2. THE CHAIN. Statements for one account must form an unbroken sequence:
 *     no overlapping periods, and each statement's opening balance equal to the
 *     previous one's closing. This is one rule and it removes three separate
 *     ways to be wrong. Overlap would let the same receipt be counted twice in
 *     the reconciliation, with no way to clear the duplicate because the
 *     one-to-one match index would refuse it. A gap would silently move an
 *     unexplained difference into the residual. And re-downloading a wider
 *     window — the most natural operator mistake — is caught by both.
 *
 *  3. IDENTITY. sha256 of the normalised, envelope-stripped text, unique across
 *     ALL bank accounts. Hashing before normalisation would let the same
 *     statement arrive twice through two channels; scoping the uniqueness per
 *     account would let it be imported a second time against the WRONG account,
 *     which reconciles one bank against another bank's movements.
 */
class BankStatementImportService
{
    public function __construct(
        private readonly Mt940Parser $mt940,
        private readonly CsvStatementParser $csv,
    ) {}

    /**
     * Parse and check without writing anything — what the operator sees before
     * committing, and the only place a CSV column mapping can be corrected.
     */
    public function preview(BankAccount $bankAccount, string $format, string $content, array $mapping = []): array
    {
        $statement = $this->parse($format, $content, $mapping);
        $blockers = $this->blockers($bankAccount, $statement, $format, $content, $mapping);

        return [
            'bank_account' => [
                'id' => $bankAccount->id,
                'code' => $bankAccount->code,
                'name' => $bankAccount->name,
                'account_no' => $bankAccount->account_no,
            ],
            'format' => $format,
            'statement' => $statement->toPreview(),
            'blockers' => $blockers,
            'can_import' => $blockers === [],
        ];
    }

    public function import(
        BankAccount $bankAccount,
        string $format,
        string $content,
        array $mapping = [],
        ?int $userId = null,
    ): BankStatement {
        $statement = $this->parse($format, $content, $mapping);
        $blockers = $this->blockers($bankAccount, $statement, $format, $content, $mapping);

        if ($blockers !== []) {
            throw new LogicException($blockers[0]);
        }

        try {
            return DB::transaction(function () use ($bankAccount, $statement, $format, $content, $mapping, $userId): BankStatement {
                $record = new BankStatement([
                    'bank_account_id' => $bankAccount->id,
                    'source_format' => $format,
                    'statement_ref' => $statement->statementRef,
                    'statement_no' => $statement->statementNo,
                    'account_identification' => $statement->accountIdentification,
                    'currency' => $statement->currency,
                    'period_start' => $statement->periodStart,
                    'period_end' => $statement->periodEnd,
                    'opening_balance' => $statement->openingCents / 100,
                    'closing_balance' => $statement->closingCents / 100,
                    'line_count' => count($statement->lines),
                    'content_hash' => $this->hash($format, $content),
                    'parse_options' => $format === BankStatementFormat::Csv->value ? $mapping : null,
                    'imported_by' => $userId,
                ]);
                $record->save();

                $now = now();

                $record->lines()->createMany(array_map(
                    static fn (array $row): array => $row + [
                        'match_status' => BankStatementMatchStatus::Open->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    array_map(fn ($line): array => $line->toRow(), $statement->lines),
                ));

                return $record->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            // Two operators uploading the same file at once: the loser gets the
            // same message as if they had gone second, not a 500.
            throw new LogicException('Berkas ini sudah diimpor.');
        }
    }

    /**
     * Deleting is the remedy for a statement read with a wrong column mapping,
     * so it is permitted — but only while nothing has been matched to it.
     * Once a line carries a match it is somebody's reconciliation evidence.
     */
    public function delete(BankStatement $statement): void
    {
        // Deleting from the middle punches a hole in exactly the chain import()
        // refuses to create: the movements in that period stay in the ledger and
        // vanish from the bank side, and the reconciliation reports the gap as a
        // bare unexplained residual nobody can clear. Only the newest statement
        // of an account can go, so the chain can only ever shorten from the end.
        $newer = BankStatement::query()
            ->where('bank_account_id', $statement->bank_account_id)
            ->whereDate('period_start', '>', $statement->period_end->toDateString())
            ->orderBy('period_start')
            ->first();

        if ($newer !== null) {
            throw new LogicException(
                "Rekening koran {$statement->code} bukan yang terbaru untuk rekening ini — {$newer->code} "
                .'menyusul setelahnya. Menghapusnya akan memutus rantai saldo. Hapus yang terbaru lebih dulu.'
            );
        }

        $matched = $statement->lines()
            ->where('match_status', BankStatementMatchStatus::Matched->value)
            ->count();

        if ($matched > 0) {
            throw new LogicException(
                "Rekening koran {$statement->code} sudah memiliki {$matched} baris yang dicocokkan. "
                .'Batalkan pencocokan itu lebih dulu bila memang perlu dihapus.'
            );
        }

        $statement->delete();
    }

    public function parse(string $format, string $content, array $mapping = []): ParsedStatement
    {
        if (trim($content) === '') {
            throw new LogicException('Berkas rekening koran kosong.');
        }

        return match (BankStatementFormat::tryFrom($format)) {
            BankStatementFormat::Mt940 => $this->mt940->parse($content),
            BankStatementFormat::Csv => $this->csv->parse($content, $mapping),
            default => throw new LogicException("Format rekening koran \"{$format}\" tidak dikenal."),
        };
    }

    /**
     * Everything that would make this import wrong, in the order an operator
     * can act on. Reported as a list rather than thrown one at a time so the
     * preview shows all of them at once.
     *
     * @return list<string>
     */
    private function blockers(
        BankAccount $bankAccount,
        ParsedStatement $statement,
        string $format,
        string $content,
        array $mapping,
    ): array {
        $blockers = [];

        if (! $statement->tiesOut()) {
            $blockers[] = sprintf(
                'Berkas tidak seimbang: saldo awal %s ditambah mutasi %s tidak sama dengan saldo akhir %s '
                .'(selisih %s). Ada baris yang belum terbaca — jangan diimpor sebelum selisihnya nol.',
                $this->rupiah($statement->openingCents),
                $this->rupiah($statement->movementCents()),
                $this->rupiah($statement->closingCents),
                $this->rupiah($statement->tieOutDifferenceCents()),
            );
        }

        if ($statement->currency !== 'IDR') {
            $blockers[] = "Rekening koran ini dalam mata uang {$statement->currency}, sedangkan buku besar "
                .'dicatat dalam Rupiah. Impor mata uang asing belum didukung.';
        }

        $duplicate = BankStatement::query()
            ->with('bankAccount')
            ->where('content_hash', $this->hash($format, $content))
            ->first();

        if ($duplicate !== null) {
            $blockers[] = $duplicate->bank_account_id === $bankAccount->id
                ? "Berkas ini sudah diimpor sebagai {$duplicate->code}."
                : "Berkas ini sudah diimpor sebagai {$duplicate->code} untuk rekening "
                    .($duplicate->bankAccount?->name ?? '?').'. Periksa rekening yang Anda pilih.';
        }

        $mismatch = $this->accountIdentificationBlocker($bankAccount, $statement);

        if ($mismatch !== null) {
            $blockers[] = $mismatch;
        }

        $blockers = array_merge($blockers, $this->chainBlockers($bankAccount, $statement));

        return array_values(array_filter($blockers));
    }

    /**
     * MT940 names the account it belongs to in :25:. Comparing it to the account
     * the operator picked is the only thing standing between a mis-click and a
     * month of one bank's movements reconciled against another's.
     *
     * Compared on digits only, and by containment rather than equality: banks
     * prefix the number with a BIC or branch code and pad it inconsistently, so
     * an equality test would refuse almost every real file.
     */
    private function accountIdentificationBlocker(BankAccount $bankAccount, ParsedStatement $statement): ?string
    {
        $declared = preg_replace('/\D/', '', (string) $statement->accountIdentification) ?? '';
        $selected = preg_replace('/\D/', '', (string) $bankAccount->account_no) ?? '';

        if (strlen($declared) < 6 || strlen($selected) < 6) {
            return null;   // nothing solid enough to compare
        }

        if (str_contains($declared, $selected) || str_contains($selected, $declared)) {
            return null;
        }

        return sprintf(
            'Rekening koran ini untuk rekening %s, sedangkan yang Anda pilih adalah %s (%s).',
            $statement->accountIdentification,
            $bankAccount->account_no,
            $bankAccount->name,
        );
    }

    /**
     * @return list<string>
     */
    private function chainBlockers(BankAccount $bankAccount, ParsedStatement $statement): array
    {
        $blockers = [];

        $overlapping = BankStatement::query()
            ->where('bank_account_id', $bankAccount->id)
            ->whereDate('period_start', '<=', $statement->periodEnd)
            ->whereDate('period_end', '>=', $statement->periodStart)
            ->first();

        if ($overlapping !== null) {
            $blockers[] = sprintf(
                'Periode %s s/d %s bertumpang tindih dengan %s (%s s/d %s). Mutasi yang sama akan terhitung dua kali.',
                $statement->periodStart,
                $statement->periodEnd,
                $overlapping->code,
                $overlapping->period_start->toDateString(),
                $overlapping->period_end->toDateString(),
            );

            return $blockers;   // the balance chain is meaningless while periods overlap
        }

        $previous = BankStatement::query()
            ->where('bank_account_id', $bankAccount->id)
            ->whereDate('period_end', '<', $statement->periodStart)
            ->orderByDesc('period_end')
            ->first();

        if ($previous !== null && $this->cents($previous->closing_balance) !== $statement->openingCents) {
            $blockers[] = sprintf(
                'Saldo awal %s tidak sama dengan saldo akhir %s pada %s. Ada periode yang belum diimpor di antaranya.',
                $this->rupiah($statement->openingCents),
                $this->rupiah($this->cents($previous->closing_balance)),
                $previous->code,
            );
        }

        $next = BankStatement::query()
            ->where('bank_account_id', $bankAccount->id)
            ->whereDate('period_start', '>', $statement->periodEnd)
            ->orderBy('period_start')
            ->first();

        if ($next !== null && $this->cents($next->opening_balance) !== $statement->closingCents) {
            $blockers[] = sprintf(
                'Saldo akhir %s tidak sama dengan saldo awal %s pada %s. Ada periode yang belum diimpor di antaranya.',
                $this->rupiah($statement->closingCents),
                $this->rupiah($this->cents($next->opening_balance)),
                $next->code,
            );
        }

        return $blockers;
    }

    /**
     * Hashed AFTER normalisation and envelope stripping, so the identity of a
     * statement is its content and not its delivery: the same file fetched over
     * SFTP and forwarded by e-mail differ only in bytes the parser ignores.
     */
    public function hash(string $format, string $content): string
    {
        $canonical = $format === BankStatementFormat::Mt940->value
            ? $this->mt940->stripEnvelope($this->mt940->normalise($content))
            : trim(str_replace(["\r\n", "\r"], "\n", ltrim($content, "\u{FEFF}")));

        return hash('sha256', $format.'|'.$canonical);
    }

    private function cents(int|float|string|null $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function rupiah(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, 2, ',', '.');
    }
}
