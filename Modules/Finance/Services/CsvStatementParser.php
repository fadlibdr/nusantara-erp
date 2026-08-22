<?php

namespace Modules\Finance\Services;

use Carbon\CarbonImmutable;
use LogicException;
use Modules\Finance\Enums\BankStatementDirection;
use Modules\Finance\Support\ParsedStatement;
use Modules\Finance\Support\ParsedStatementLine;

/**
 * CSV rekening koran parser, driven by a mapping the operator declares.
 *
 * MAPPED, NEVER SNIFFED. There is no canonical Indonesian bank CSV: BCA,
 * Mandiri, BNI and BRI each emit a different layout, none publishes a column
 * spec, and the same bank changes it between channels. A sniffer that guesses
 * right nine times and wrong once produces a statement that imports, ties out
 * and is wrong — so the operator declares the columns, sees the parsed result,
 * and only then commits.
 *
 * That makes one thing weaker than it looks, and it is worth being blunt about:
 * for MT940 the tie-out is evidence, because the bank asserted the opening and
 * closing balances independently of the movements. For CSV the operator types
 * both endpoints, so "opening + movements = closing" can be satisfied by
 * adjusting an endpoint until it is. The honest fix is the Saldo column, which
 * every Indonesian rekening koran carries: when balance_column is mapped, each
 * row's running balance is checked against the previous row plus the movement,
 * which turns the operator's assertion back into the file's own arithmetic and
 * localises a dropped row to the exact line. When it is not mapped, the screen
 * says so rather than presenting the check as proof.
 */
class CsvStatementParser
{
    public const DATE_FORMATS = [
        'dd/mm/yyyy' => '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/',
        'dd-mm-yyyy' => '/^(\d{1,2})-(\d{1,2})-(\d{4})$/',
        'yyyy-mm-dd' => '/^(\d{4})-(\d{1,2})-(\d{1,2})$/',
        'dd/mm/yy' => '/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/',
        'dd/mm' => '/^(\d{1,2})\/(\d{1,2})$/',
    ];

    public const AMOUNT_MODES = ['debit_credit', 'single_signed', 'single_with_indicator'];

    public const NUMBER_FORMATS = ['id', 'en'];

    public const DELIMITERS = [',' => ',', ';' => ';', '|' => '|', 'tab' => "\t"];

    private const DEBIT_TOKENS = ['D', 'DB', 'DR', 'DEBET', 'DEBIT'];

    /** K is Kredit — the Indonesian marker, and the one an English reader misreads. */
    private const CREDIT_TOKENS = ['C', 'CR', 'K', 'KR', 'KREDIT', 'CREDIT'];

    /**
     * @param  array  $mapping  see UpdateBankStatementImportRequest for the shape
     */
    public function parse(string $text, array $mapping): ParsedStatement
    {
        $periodStart = CarbonImmutable::parse($mapping['period_start']);
        $periodEnd = CarbonImmutable::parse($mapping['period_end']);

        if ($periodEnd->lessThan($periodStart)) {
            throw new LogicException('Tanggal akhir periode lebih awal daripada tanggal mulai.');
        }

        $openingCents = $this->toCents($mapping['opening_balance']);
        $closingCents = $this->toCents($mapping['closing_balance']);

        $rows = $this->readRows($text, $mapping);
        $warnings = [];
        $lines = [];
        $lineNo = 0;
        $runningCents = $openingCents;
        $balanceChecks = 0;

        foreach ($rows as $rowNo => $fields) {
            $dateCell = $this->cell($fields, $mapping['date_column'] ?? null);

            if ($this->isContinuation($fields, $mapping, $dateCell)) {
                if ($lines === []) {
                    $warnings[] = "Baris {$rowNo} tampak sebagai lanjutan keterangan tetapi belum ada mutasi sebelumnya; baris dilewati.";

                    continue;
                }

                $continued = $this->cell($fields, $mapping['description_column'] ?? null);
                $last = array_pop($lines);
                $lines[] = $this->withDescription($last, trim($last->description.' '.$continued));

                continue;
            }

            [$direction, $amountCents] = $this->movementFor($fields, $mapping, $rowNo);

            if ($amountCents === 0) {
                $warnings[] = "Baris {$rowNo} bernilai nol dan tidak dicatat sebagai mutasi.";

                continue;
            }

            $lineNo++;
            $entryDate = $this->parseDate($dateCell, $mapping, $periodStart, $periodEnd, $rowNo);
            $signed = $direction->sign() * $amountCents;
            $runningCents += $signed;

            $balanceChecks += $this->assertRunningBalance($fields, $mapping, $runningCents, $rowNo);

            $lines[] = new ParsedStatementLine(
                lineNo: $lineNo,
                entryDate: $entryDate,
                valueDate: null,
                direction: $direction,
                amountCents: $amountCents,
                description: $this->clip($this->cell($fields, $mapping['description_column'] ?? null), 2000) ?: null,
                customerReference: $this->clip($this->cell($fields, $mapping['reference_column'] ?? null), 64) ?: null,
                rawLine: $this->clip(implode($this->delimiter($mapping), $fields), 2000),
            );

            if ($entryDate < $periodStart->toDateString() || $entryDate > $periodEnd->toDateString()) {
                $warnings[] = "Baris {$rowNo} bertanggal {$entryDate}, di luar periode yang dinyatakan.";
            }
        }

        if ($lines === []) {
            throw new LogicException('Tidak ada baris mutasi yang terbaca. Periksa jumlah baris judul dan pemetaan kolom.');
        }

        if (! isset($mapping['balance_column'])) {
            $warnings[] = 'Kolom saldo tidak dipetakan, sehingga keseimbangan hanya diuji terhadap saldo awal dan '
                .'akhir yang Anda ketik sendiri — bukan terhadap angka bank. Petakan kolom Saldo bila ada.';
        } elseif ($balanceChecks === 0) {
            $warnings[] = 'Kolom saldo yang dipetakan kosong pada semua baris, jadi tidak ada satu pun '
                .'pemeriksaan saldo berjalan yang benar-benar dijalankan. Periksa nomor kolomnya.';
        }

        // 1.234.567,89 in a comma-delimited file splits on its own decimal
        // comma unless the bank quoted the field. Almost every Indonesian
        // export therefore uses a semicolon, and an operator who picks the
        // wrong pair here gets numbers that parse and are a thousand times too
        // small rather than an error.
        if (($mapping['delimiter'] ?? ',') === ',' && ($mapping['number_format'] ?? 'id') === 'id') {
            $warnings[] = 'Pemisah kolom koma dipakai bersama format angka Indonesia (1.234.567,89), yang juga '
                .'memakai koma sebagai desimal. Periksa nilai di pratinjau; berkas seperti ini biasanya '
                .'memakai titik koma sebagai pemisah kolom.';
        }

        return new ParsedStatement(
            currency: 'IDR',
            periodStart: $periodStart->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            openingCents: $openingCents,
            closingCents: $closingCents,
            lines: $lines,
            warnings: $warnings,
        );
    }

    /**
     * fgetcsv over a memory stream, so a quoted field containing the delimiter
     * or a newline survives — which str_getcsv per physical line would not.
     *
     * @return array<int, list<string>> keyed by 1-based row number in the file
     */
    private function readRows(string $text, array $mapping): array
    {
        $delimiter = $this->delimiter($mapping);
        $skip = (int) ($mapping['skip_rows'] ?? 0);

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new LogicException('Berkas tidak dapat dibaca.');
        }

        fwrite($handle, str_replace(["\r\n", "\r"], "\n", ltrim($text, "\u{FEFF}")));
        rewind($handle);

        $rows = [];
        $rowNo = 0;

        while (($fields = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rowNo++;

            if ($rowNo <= $skip) {
                continue;
            }

            // fgetcsv yields [null] for a blank line.
            if ($fields === [null] || $this->isBlank($fields)) {
                continue;
            }

            $rows[$rowNo] = array_map(static fn ($value): string => trim((string) $value), $fields);
        }

        fclose($handle);

        return $rows;
    }

    private function delimiter(array $mapping): string
    {
        $token = (string) ($mapping['delimiter'] ?? ',');

        // fgetcsv throws a ValueError on anything but a single character, and a
        // ValueError is not a LogicException — it would leave the controller and
        // surface as a 500 rather than a message the operator can act on.
        return self::DELIMITERS[$token] ?? throw new LogicException("Pemisah kolom \"{$token}\" tidak dikenal.");
    }

    /**
     * A wrapped description: no date, no amount anywhere, but text present.
     * Anything with an amount is a movement, however odd it looks.
     */
    private function isContinuation(array $fields, array $mapping, string $dateCell): bool
    {
        if ($dateCell !== '') {
            return false;
        }

        foreach (['debit_column', 'credit_column', 'amount_column', 'balance_column'] as $key) {
            if (isset($mapping[$key]) && $this->cell($fields, $mapping[$key]) !== '') {
                return false;
            }
        }

        return $this->cell($fields, $mapping['description_column'] ?? null) !== '';
    }

    /**
     * @return array{0: BankStatementDirection, 1: int}
     */
    private function movementFor(array $fields, array $mapping, int $rowNo): array
    {
        $mode = (string) $mapping['amount_mode'];

        if ($mode === 'debit_credit') {
            $debit = $this->amountCents($this->cell($fields, $mapping['debit_column'] ?? null), $mapping, $rowNo);
            $credit = $this->amountCents($this->cell($fields, $mapping['credit_column'] ?? null), $mapping, $rowNo);

            if ($debit !== 0 && $credit !== 0) {
                throw new LogicException("Baris {$rowNo} mengisi kolom debit dan kredit sekaligus.");
            }

            return $debit !== 0
                ? [BankStatementDirection::Debit, abs($debit)]
                : [BankStatementDirection::Credit, abs($credit)];
        }

        $cell = $this->cell($fields, $mapping['amount_column'] ?? null);

        if ($mode === 'single_signed') {
            $value = $this->amountCents($cell, $mapping, $rowNo);

            return $value < 0
                ? [BankStatementDirection::Debit, -$value]
                : [BankStatementDirection::Credit, $value];
        }

        // single_with_indicator: the marker may be its own column, or a tail on
        // the amount cell itself ("500.000,00 DB"), which is why the cell is
        // tokenised into number and marker before either is interpreted.
        [$numeric, $tail] = $this->tokenise($cell);
        $indicator = isset($mapping['indicator_column'])
            ? $this->cell($fields, $mapping['indicator_column'])
            : $tail;

        $value = abs($this->amountCents($numeric, $mapping, $rowNo));
        $token = strtoupper(preg_replace('/[^A-Za-z]/', '', $indicator) ?? '');

        if (in_array($token, self::DEBIT_TOKENS, true)) {
            return [BankStatementDirection::Debit, $value];
        }

        if (in_array($token, self::CREDIT_TOKENS, true)) {
            return [BankStatementDirection::Credit, $value];
        }

        if ($value === 0) {
            return [BankStatementDirection::Credit, 0];   // dropped by the caller
        }

        throw new LogicException(
            "Baris {$rowNo} tidak menyatakan debit atau kredit (nilai penanda: \"{$indicator}\")."
        );
    }

    /**
     * Splits "500.000,00 DB" into its number and its marker. Running this
     * BEFORE the number parser is what keeps the parser strict: it can then
     * refuse anything that is not a number, instead of needing a fallback that
     * would also swallow genuinely malformed cells.
     *
     * @return array{0: string, 1: string}
     */
    private function tokenise(string $cell): array
    {
        $numeric = trim(preg_replace('/[^0-9.,+-]/', '', $cell) ?? '');
        $marker = trim(preg_replace('/[^A-Za-z]/', '', $cell) ?? '');

        return [$numeric, $marker];
    }

    private function amountCents(string $value, array $mapping, int $rowNo): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-') {
            return 0;
        }

        $format = (string) ($mapping['number_format'] ?? 'id');

        // A trailing minus ("1.000.000-", which some exports use for a debit) and
        // a lone parenthesis are read, not trimmed away: trimming them turns a
        // debit into a credit silently.
        $bracketed = str_starts_with($value, '(') && str_ends_with($value, ')');

        if (! $bracketed && (str_contains($value, '(') || str_contains($value, ')'))) {
            throw new LogicException("Baris {$rowNo}: nilai \"{$value}\" memiliki kurung yang tidak berpasangan.");
        }

        $negative = $bracketed || str_starts_with($value, '-') || str_ends_with($value, '-');
        $digits = trim($value, '()+-');

        $pattern = $format === 'id'
            ? '/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/'
            : '/^\d{1,3}(,\d{3})*(\.\d{1,2})?$|^\d+(\.\d{1,2})?$/';

        if (preg_match($pattern, $digits) !== 1) {
            throw new LogicException(
                "Baris {$rowNo}: nilai \"{$value}\" bukan angka dalam format ".
                ($format === 'id' ? 'Indonesia (1.234.567,89)' : 'Inggris (1,234,567.89)').'.'
            );
        }

        $normalised = $format === 'id'
            ? str_replace(',', '.', str_replace('.', '', $digits))
            : str_replace(',', '', $digits);

        [$whole, $fraction] = array_pad(explode('.', $normalised, 2), 2, '');

        // decimal(18,2) tops out at 16 integer digits, and beyond that the cents
        // arithmetic overflows into a float and the multiplication throws a
        // TypeError — which is not a LogicException, so it would leave the
        // controller as an HTTP 500 rather than a message about the mapping.
        if (strlen($whole) > 15) {
            throw new LogicException(
                "Baris {$rowNo}: nilai \"{$value}\" terlalu besar untuk sebuah mutasi — periksa pemetaan kolomnya."
            );
        }

        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    /**
     * The one independent check available on a CSV: the bank's own running
     * balance. A row dropped by a bad mapping shows up here, at its own row
     * number, instead of as a global discrepancy the operator is tempted to
     * absorb by editing an endpoint.
     */
    private function assertRunningBalance(array $fields, array $mapping, int $runningCents, int $rowNo): int
    {
        if (! isset($mapping['balance_column'])) {
            return 0;
        }

        $cell = $this->cell($fields, $mapping['balance_column']);

        if ($cell === '') {
            return 0;
        }

        [$numeric, $marker] = $this->tokenise($cell);
        $reported = $this->amountCents($numeric, $mapping, $rowNo);

        // An overdrawn balance is written either with a sign or with a DB/D
        // marker beside it. Reading only the digits turns an overdraft into a
        // credit balance and refuses a correct statement.
        if ($reported > 0 && in_array(strtoupper($marker), self::DEBIT_TOKENS, true)) {
            $reported = -$reported;
        }

        if ($reported !== $runningCents) {
            throw new LogicException(sprintf(
                'Baris %d: saldo berjalan tidak cocok. Menurut berkas %s, menurut perhitungan %s. '
                .'Biasanya ada baris mutasi yang belum terbaca tepat di atas baris ini.',
                $rowNo,
                number_format($reported / 100, 2, ',', '.'),
                number_format($runningCents / 100, 2, ',', '.'),
            ));
        }

        return 1;
    }

    /**
     * A dd/mm date has no year. Rather than guess from the neighbouring rows,
     * the year is the one — of the declared period's start or end year — that
     * places the date inside the declared period. That is deterministic, and it
     * gets 31/12 on a January statement right, which a "did the date jump
     * backwards" heuristic does not.
     */
    private function parseDate(
        string $value,
        array $mapping,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        int $rowNo,
    ): string {
        $format = (string) $mapping['date_format'];
        $pattern = self::DATE_FORMATS[$format] ?? throw new LogicException("Format tanggal \"{$format}\" tidak dikenal.");

        if (preg_match($pattern, $value, $m) !== 1) {
            throw new LogicException("Baris {$rowNo}: tanggal \"{$value}\" tidak sesuai format {$format}.");
        }

        if ($format === 'yyyy-mm-dd') {
            return $this->assertRealDate((int) $m[3], (int) $m[2], (int) $m[1], $value, $rowNo);
        }

        $day = (int) $m[1];
        $month = (int) $m[2];

        if ($format === 'dd/mm') {
            foreach ([$periodStart->year, $periodEnd->year] as $year) {
                if (! checkdate($month, $day, $year)) {
                    continue;
                }

                $candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);

                if ($candidate >= $periodStart->toDateString() && $candidate <= $periodEnd->toDateString()) {
                    return $candidate;
                }
            }

            return $this->assertRealDate($day, $month, $periodStart->year, $value, $rowNo);
        }

        $year = (int) $m[3];

        if ($format === 'dd/mm/yy') {
            $year += 2000;
        }

        return $this->assertRealDate($day, $month, $year, $value, $rowNo);
    }

    /**
     * 31/02 matches the pattern and is not a date. Left alone, Eloquent's cast
     * rolls it into March and the stored line silently disagrees with the
     * preview the operator approved.
     */
    private function assertRealDate(int $day, int $month, int $year, string $value, int $rowNo): string
    {
        if (! checkdate($month, $day, $year)) {
            throw new LogicException("Baris {$rowNo}: tanggal \"{$value}\" bukan tanggal yang ada dalam kalender.");
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function withDescription(ParsedStatementLine $line, string $description): ParsedStatementLine
    {
        return new ParsedStatementLine(
            lineNo: $line->lineNo,
            entryDate: $line->entryDate,
            valueDate: $line->valueDate,
            direction: $line->direction,
            amountCents: $line->amountCents,
            description: $this->clip($description, 2000),
            customerReference: $line->customerReference,
            bankReference: $line->bankReference,
            transactionCode: $line->transactionCode,
            isReversal: $line->isReversal,
            rawLine: $line->rawLine,
        );
    }

    private function cell(array $fields, int|string|null $index): string
    {
        if ($index === null) {
            return '';
        }

        return trim((string) ($fields[(int) $index] ?? ''));
    }

    private function isBlank(array $fields): bool
    {
        foreach ($fields as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function toCents(int|float|string $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function clip(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
