<?php

namespace Modules\Finance\Services;

use LogicException;
use Modules\Finance\Enums\BankStatementDirection;
use Modules\Finance\Support\ParsedStatement;
use Modules\Finance\Support\ParsedStatementLine;

/**
 * SWIFT MT940 customer statement parser.
 *
 * Every refusal in here exists because the alternative is a file that imports
 * cleanly, ties out, and is missing money. Four in particular:
 *
 *  - a delivery file holds MANY {4:…-} blocks (one per statement, and a daily
 *    MT940 product means twenty per month). Reading only the first one gives a
 *    statement whose opening, lines and closing are internally consistent and
 *    nineteen days short. Every block is read;
 *  - a statement split across pages carries :60F: on the first page and :62F:
 *    on the last, with :60M:/:62M: between. Take pages 1..2 of a 3-page
 *    statement and the balances still chain — 62M(1) == 60M(2) — and the merged
 *    result ties out perfectly while page 3 never existed. So the first page's
 *    opening MUST be final (60F) and the last page's closing MUST be final
 *    (62F); "M" literally means more follows;
 *  - a file may carry several accounts. The account check therefore runs across
 *    ALL pages before anything is grouped, because a check applied after
 *    grouping by :25: can never see two accounts;
 *  - :61: subfield 7/8 is `16x[//16x]`. A greedy 16-character match on a value
 *    like NONREF//6127001795151001 swallows the separator and returns
 *    "NONREF//61270017" as the customer reference and nothing as the bank
 *    reference — no error, just the loss of the strongest matching signal on
 *    every line in the file. The tail is split on the first // before the
 *    field regex sees it.
 *
 * MT942 (interim/intraday) is refused rather than half-supported: it carries no
 * balance tags, so the tie-out cannot run, and its entries are provisional —
 * they change amount or vanish before end of day and reappear in the next MT940
 * under a different reference.
 */
class Mt940Parser
{
    /** :61: — value date, [entry date], D/C mark, [funds code], amount, type, refs. */
    private const LINE_PATTERN = '/^(\d{6})(\d{4})?(RC|RD|C|D)([A-Z])?(\d{1,15},\d{0,2})([A-Z][A-Z0-9]{3})(.*)$/s';

    /** :60F: / :62F: / :60M: / :62M: — D/C mark, date, currency, amount. */
    private const BALANCE_PATTERN = '/^([DC])(\d{6})([A-Z]{3})(\d{1,15},\d{0,2})$/';

    /**
     * The reversal marks are the whole reason this is a map and not a
     * str_starts_with: RC reverses a credit, so its net effect on the account is
     * a DEBIT. Getting that backwards moves money the wrong way on exactly the
     * lines a reader is least likely to check by hand.
     */
    private const MARKS = [
        'C' => [BankStatementDirection::Credit, false],
        'D' => [BankStatementDirection::Debit, false],
        'RC' => [BankStatementDirection::Debit, true],
        'RD' => [BankStatementDirection::Credit, true],
    ];

    public function parse(string $text): ParsedStatement
    {
        $body = $this->stripEnvelope($this->normalise($text));

        $this->assertNotInterimReport($body);

        $pages = $this->splitPages($body);

        if ($pages === []) {
            throw new LogicException('Berkas ini tidak berisi pesan MT940 (tag :20: tidak ditemukan).');
        }

        $this->assertSingleAccount($pages);

        return $this->mergePages($pages);
    }

    /**
     * Normalisation is part of the identity of the file — the import hashes the
     * result of this, so the same statement delivered over SFTP and over an
     * e-mail gateway is recognised as the same statement rather than imported
     * twice.
     */
    public function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = ltrim($text, "\u{FEFF}");
        $lines = array_map(static fn (string $line): string => rtrim($line), explode("\n", $text));

        return trim(implode("\n", $lines));
    }

    /**
     * Keep the contents of every {4:…-} application block. A file with no
     * envelope at all — the common shape of a downloaded statement — is used as
     * it stands.
     */
    public function stripEnvelope(string $text): string
    {
        $opened = substr_count($text, '{4:');

        if ($opened === 0) {
            return $text;
        }

        preg_match_all('/\{4:\s*(.*?)\s*-\}/s', $text, $matches);
        $blocks = $matches[1] ?? [];

        // An application block without its "-}" terminator matches nothing and
        // would simply vanish, leaving a file that ties out and is missing a
        // day. A truncated delivery is refused, not quietly trimmed.
        if (count($blocks) !== $opened) {
            throw new LogicException(sprintf(
                'Berkas memuat %d blok pesan SWIFT tetapi hanya %d yang ditutup dengan "-}". '
                .'Berkas ini terpotong saat dikirim.',
                $opened,
                count($blocks),
            ));
        }

        return implode("\n", array_map(static fn (string $block): string => trim($block), $blocks));
    }

    private function assertNotInterimReport(string $body): void
    {
        $hasBalance = str_contains($body, ':60F:') || str_contains($body, ':60M:');
        $looksInterim = str_contains($body, ':34F:') || str_contains($body, ':13D:');

        if ($looksInterim && ! $hasBalance) {
            throw new LogicException(
                'Berkas ini MT942 (laporan sementara/intraday), bukan MT940. MT942 tidak memuat saldo awal '
                .'dan akhir sehingga kebenarannya tidak dapat diuji, dan mutasinya masih dapat berubah. '
                .'Impor rekening koran MT940 akhir hari.'
            );
        }
    }

    /**
     * One page = one :20:-headed message. Fields, not lines: a value continues
     * onto following lines until the next :NN: tag, so :86: descriptions and
     * :61: supplementary details are accumulated rather than truncated.
     *
     * @return list<array<string, list<string>>>
     */
    private function splitPages(string $body): array
    {
        $pages = [];
        $current = null;
        $tag = null;
        $movements = 0;
        $afterClosing = false;

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/s', $line, $m) === 1) {
                $tag = $m[1];

                if ($tag === '20') {
                    if ($current !== null) {
                        $pages[] = $current;
                    }
                    $current = [];
                    $movements = 0;
                    $afterClosing = false;
                }

                if ($current === null) {
                    continue; // preamble before the first :20:
                }

                if ($tag === '61') {
                    $movements++;
                }

                if ($tag === '62F' || $tag === '62M') {
                    $afterClosing = true;
                }

                // Where this :86: sits relative to the movements, so a
                // statement-level one can be told from a transaction one no
                // matter which end of the message the bank puts it at.
                if ($tag === '86') {
                    $current['86_at'][] = ['movement' => $movements, 'after_closing' => $afterClosing];
                }

                $current[$tag][] = $m[2];

                continue;
            }

            // A continuation of the field opened above. Appended to the LAST
            // occurrence of that tag, which for :61:/:86: is the current line.
            if ($current !== null && $tag !== null && isset($current[$tag]) && $line !== '') {
                $last = count($current[$tag]) - 1;
                $current[$tag][$last] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $pages[] = $current;
        }

        return $pages;
    }

    /**
     * Runs across every page before any grouping. Grouping first and checking
     * afterwards is the classic dead check: a group keyed on the account can
     * never contain two accounts.
     *
     * @param  list<array<string, list<string>>>  $pages
     */
    private function assertSingleAccount(array $pages): void
    {
        $accounts = [];

        foreach ($pages as $page) {
            $account = trim($page['25'][0] ?? '');

            if ($account !== '') {
                $accounts[$account] = true;
            }
        }

        if (count($accounts) > 1) {
            throw new LogicException(
                'Berkas ini memuat lebih dari satu rekening ('.implode(', ', array_keys($accounts)).'). '
                .'Impor ditujukan ke satu rekening bank; pisahkan berkasnya per rekening.'
            );
        }
    }

    /**
     * A delivery file legitimately holds many statements, but never the same one
     * twice. Without this, a re-sent message pairs with itself: the balances
     * still chain (each page opens where the last closed only if the movement
     * nets to zero, which a reversal pair does), the file ties out, and every
     * line is imported twice.
     *
     * @param  list<array<string, mixed>>  $parsed
     */
    private function assertNoRepeatedPage(array $parsed): void
    {
        $seen = [];

        foreach ($parsed as $page) {
            $key = ($page['reference'] ?? '').'|'.($page['statement_no'] ?? '');

            if (isset($seen[$key])) {
                throw new LogicException(
                    "Berkas memuat pesan yang sama dua kali (:20: {$page['reference']}, :28C: {$page['statement_no']}). "
                    .'Setiap mutasi akan terhitung dua kali.'
                );
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  list<array<string, list<string>>>  $pages
     */
    private function mergePages(array $pages): ParsedStatement
    {
        $parsed = [];

        foreach ($pages as $page) {
            $parsed[] = $this->parsePage($page);
        }

        usort($parsed, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        $this->assertNoRepeatedPage($parsed);

        $first = $parsed[0];
        $last = $parsed[count($parsed) - 1];
        $warnings = [];

        if (! $first['opening_final']) {
            throw new LogicException(
                'Halaman pertama rekening koran memakai saldo awal sementara (:60M:), bukan :60F:. '
                .'Berkas ini terpotong di awal — halaman sebelumnya belum ikut.'
            );
        }

        if (! $last['closing_final']) {
            throw new LogicException(
                'Halaman terakhir rekening koran memakai saldo akhir sementara (:62M:), bukan :62F:. '
                .'":62M:" berarti masih ada halaman berikutnya — berkas ini terpotong.'
            );
        }

        $currencies = array_unique(array_merge(
            array_column($parsed, 'opening_currency'),
            array_column($parsed, 'closing_currency'),
        ));

        if (count($currencies) > 1) {
            throw new LogicException('Saldo dalam berkas ini memakai lebih dari satu mata uang: '.implode(', ', $currencies).'.');
        }

        $lines = [];
        $lineNo = 0;

        foreach ($parsed as $index => $page) {
            if ($index > 0) {
                $previous = $parsed[$index - 1];

                if ($previous['closing_cents'] !== $page['opening_cents']) {
                    throw new LogicException(sprintf(
                        'Saldo antar halaman tidak menyambung: halaman %s ditutup pada %s tetapi halaman %s dibuka pada %s.',
                        $previous['statement_no'] ?? implode('/', $previous['sequence']),
                        number_format($previous['closing_cents'] / 100, 2, ',', '.'),
                        $page['statement_no'] ?? implode('/', $page['sequence']),
                        number_format($page['opening_cents'] / 100, 2, ',', '.'),
                    ));
                }
            }

            foreach ($page['lines'] as $line) {
                $lineNo++;
                $lines[] = new ParsedStatementLine(
                    lineNo: $lineNo,
                    entryDate: $line->entryDate,
                    valueDate: $line->valueDate,
                    direction: $line->direction,
                    amountCents: $line->amountCents,
                    description: $line->description,
                    customerReference: $line->customerReference,
                    bankReference: $line->bankReference,
                    transactionCode: $line->transactionCode,
                    isReversal: $line->isReversal,
                    rawLine: $line->rawLine,
                );
            }

            $warnings = array_merge($warnings, $page['warnings']);
        }

        return new ParsedStatement(
            currency: $first['opening_currency'],
            periodStart: $first['opening_date'],
            periodEnd: $last['closing_date'],
            openingCents: $first['opening_cents'],
            closingCents: $last['closing_cents'],
            lines: $lines,
            warnings: array_values(array_unique($warnings)),
            statementRef: $first['reference'],
            statementNo: $first['statement_no'],
            accountIdentification: $first['account'],
        );
    }

    /**
     * @param  array<string, list<string>>  $page
     */
    private function parsePage(array $page): array
    {
        $openingTag = isset($page['60F']) ? '60F' : (isset($page['60M']) ? '60M' : null);
        $closingTag = isset($page['62F']) ? '62F' : (isset($page['62M']) ? '62M' : null);

        if ($openingTag === null || $closingTag === null) {
            throw new LogicException(
                'Rekening koran tidak memuat saldo awal (:60F:) atau saldo akhir (:62F:). '
                .'Tanpa keduanya kebenaran berkas tidak dapat diuji.'
            );
        }

        $opening = $this->parseBalance(trim($page[$openingTag][0]), $openingTag);
        $closing = $this->parseBalance(trim($page[$closingTag][0]), $closingTag);

        $statementNo = isset($page['28C']) ? trim($page['28C'][0]) : null;
        $descriptions = $this->descriptionsFor($page);
        $warnings = [];
        $lines = [];
        $lineNo = 0;

        foreach ($page['61'] ?? [] as $index => $raw) {
            $lineNo++;
            $lines[] = $this->parseLine(
                $lineNo,
                $raw,
                $opening['date'],
                $descriptions[$index] ?? null,
            );
        }

        if (($page['61'] ?? []) === []) {
            $warnings[] = 'Halaman '.($statementNo ?? '1').' tidak memuat mutasi.';
        }

        return [
            'sequence' => $this->sequenceOf($statementNo),
            'statement_no' => $statementNo,
            'reference' => isset($page['20']) ? trim($page['20'][0]) : null,
            'account' => isset($page['25']) ? trim($page['25'][0]) : null,
            'opening_final' => $openingTag === '60F',
            'closing_final' => $closingTag === '62F',
            'opening_cents' => $opening['cents'],
            'closing_cents' => $closing['cents'],
            'opening_currency' => $opening['currency'],
            'closing_currency' => $closing['currency'],
            'opening_date' => $opening['date'],
            'closing_date' => $closing['date'],
            'lines' => $lines,
            'warnings' => $warnings,
        ];
    }

    /**
     * :86: describes the :61: it FOLLOWS. A page may also carry a statement-level
     * :86: that describes the whole statement rather than a movement, and banks
     * put it at either end — before the first movement, or after :62F:.
     *
     * Pairing by count instead of by position is the trap here: assume the extra
     * one is at the front and a bank that puts it at the back shifts every
     * description onto the next movement. Nothing errors, every count still
     * matches, and the operator matches transactions against other
     * transactions' narratives. So the position is recorded while parsing and
     * used here, and an :86: that belongs to no movement is dropped.
     *
     * @param  array<string, mixed>  $page
     * @return array<int, string> keyed by movement index
     */
    private function descriptionsFor(array $page): array
    {
        $info = $page['86'] ?? [];
        $positions = $page['86_at'] ?? [];
        $descriptions = [];

        foreach ($info as $index => $value) {
            $at = $positions[$index] ?? null;

            // No preceding movement, or it came after the closing balance:
            // statement-level, not a transaction narrative.
            if ($at === null || $at['movement'] === 0 || $at['after_closing']) {
                continue;
            }

            $movement = $at['movement'] - 1;
            $text = $this->flatten($value);

            $descriptions[$movement] = isset($descriptions[$movement])
                ? trim($descriptions[$movement].' '.$text)
                : $text;
        }

        return $descriptions;
    }

    private function parseLine(int $lineNo, string $raw, string $openingDate, ?string $description): ParsedStatementLine
    {
        $raw = trim($raw);
        // Supplementary details live on a continuation line; only the first line
        // carries the field structure.
        [$head, $supplementary] = array_pad(explode("\n", $raw, 2), 2, null);

        if (preg_match(self::LINE_PATTERN, trim((string) $head), $m) !== 1) {
            throw new LogicException("Baris mutasi ke-{$lineNo} tidak dapat dibaca: \"".trim((string) $head).'".');
        }

        [, $valueDate, $entryDate, $mark, , $amount, $transactionCode, $tail] = $m;

        [$direction, $isReversal] = self::MARKS[$mark];

        $valueDateIso = $this->dateFromYymmdd($valueDate);
        $entryDateIso = $entryDate === ''
            ? $valueDateIso
            : $this->entryDateFromMmdd($entryDate, $valueDateIso);

        // Split on the FIRST // so a reference containing 16+ characters before
        // the separator cannot swallow it.
        $tail = trim((string) $tail);
        $separator = strpos($tail, '//');
        $customerReference = $separator === false ? $tail : substr($tail, 0, $separator);
        $bankReference = $separator === false ? null : substr($tail, $separator + 2);

        $detail = trim(implode(' ', array_filter([
            $description,
            $supplementary === null ? null : $this->flatten($supplementary),
        ])));

        return new ParsedStatementLine(
            lineNo: $lineNo,
            entryDate: $entryDateIso,
            valueDate: $valueDateIso,
            direction: $direction,
            amountCents: $this->amountToCents($amount, "baris mutasi ke-{$lineNo}"),
            description: $detail === '' ? null : $this->clip($detail, 2000),
            customerReference: $this->clip(trim($customerReference), 64) ?: null,
            bankReference: $bankReference === null ? null : ($this->clip(trim($bankReference), 64) ?: null),
            transactionCode: $this->clip($transactionCode, 8),
            isReversal: $isReversal,
            rawLine: $this->clip($raw, 2000),
        );
    }

    private function parseBalance(string $value, string $tag): array
    {
        if (preg_match(self::BALANCE_PATTERN, $value, $m) !== 1) {
            throw new LogicException("Saldo pada tag :{$tag}: tidak dapat dibaca: \"{$value}\".");
        }

        [, $mark, $date, $currency, $amount] = $m;
        $cents = $this->amountToCents($amount, "saldo :{$tag}:");

        return [
            // A credit balance is money the customer HAS: positive. A debit
            // balance is an overdraft.
            'cents' => $mark === 'C' ? $cents : -$cents,
            'currency' => $currency,
            'date' => $this->dateFromYymmdd($date),
        ];
    }

    /**
     * Amounts are 15d with a MANDATORY decimal comma. A value that reaches here
     * without one is not an MT940 amount, and guessing an implied decimal place
     * is how a statement gets imported at a hundredth of its real value.
     */
    private function amountToCents(string $amount, string $where): int
    {
        [$whole, $fraction] = array_pad(explode(',', $amount, 2), 2, '');

        if (strlen($fraction) > 2) {
            throw new LogicException("Nilai pada {$where} memiliki lebih dari dua angka desimal: \"{$amount}\".");
        }

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * SWIFT dates are YYMMDD with no century. The 1980 pivot is the SWIFT
     * convention and is safe for statements, which are never historical.
     */
    private function dateFromYymmdd(string $value): string
    {
        $year = (int) substr($value, 0, 2);
        $year += $year >= 80 ? 1900 : 2000;

        return $this->assertRealDate((int) substr($value, 4, 2), (int) substr($value, 2, 2), $year, $value);
    }

    /**
     * The entry date carries MMDD only. It normally shares the value date's
     * year, but a statement spanning New Year has entry 0102 against value
     * 251231 — so a month that has gone BACKWARDS means the entry is in the
     * following year, and forwards across a year end means the previous one.
     */
    private function entryDateFromMmdd(string $mmdd, string $valueDateIso): string
    {
        $year = (int) substr($valueDateIso, 0, 4);
        $valueMonth = (int) substr($valueDateIso, 5, 2);
        $entryMonth = (int) substr($mmdd, 0, 2);

        if ($valueMonth === 12 && $entryMonth === 1) {
            $year++;
        } elseif ($valueMonth === 1 && $entryMonth === 12) {
            $year--;
        }

        return $this->assertRealDate((int) substr($mmdd, 2, 2), $entryMonth, $year, $mmdd);
    }

    /**
     * 260231 parses and is not a date. Eloquent's cast would roll it into March
     * and the stored line would silently disagree with the file.
     */
    private function assertRealDate(int $day, int $month, int $year, string $raw): string
    {
        if (! checkdate($month, $day, $year)) {
            throw new LogicException("Tanggal \"{$raw}\" bukan tanggal yang ada dalam kalender.");
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function flatten(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $value)) ?? '');
    }

    private function clip(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    /**
     * :28C: is "5n[/5n]" — statement number, optionally a page sequence within
     * it. Both matter, in that order: sorting a delivery file by page number
     * alone interleaves page 1 of every statement, then page 2 of every
     * statement, and the balance chain then fails on a perfectly good file.
     *
     * @return array{0: int, 1: int} [statement number, page]
     */
    private function sequenceOf(?string $statementNo): array
    {
        if ($statementNo === null || $statementNo === '') {
            return [0, 1];
        }

        $parts = explode('/', $statementNo);
        $statement = (int) preg_replace('/\D/', '', $parts[0]);
        $page = count($parts) > 1 ? (int) preg_replace('/\D/', '', $parts[1]) : 1;

        return [$statement, $page];
    }
}
