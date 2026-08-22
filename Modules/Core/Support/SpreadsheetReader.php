<?php

namespace Modules\Core\Support;

use Carbon\Carbon;
use InvalidArgumentException;
use LogicException;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * The one place a spreadsheet becomes rows, a cell becomes a value, and a value
 * becomes a cell again.
 *
 * Every method body here was MOVED out of MasterDataImportService rather than
 * copied, because two of them carry a bug that was expensive to find and would
 * not have been re-found by whoever wrote the second importer:
 *
 *   readCsv()   PhpSpreadsheet sniffs the delimiter across the whole file, so
 *               the template's own "# wajib diisi: kode, nama" hint made it
 *               decide the file was space-separated and hand back one column.
 *   castText()  A 16-digit NIK read by any spreadsheet is a float, and PHP then
 *               stringifies it as 3.2010101019E+15 — a valid identity card
 *               refused for a fault of the reader's.
 *
 * After the move there is no second copy, and MasterDataImportTest (BOM,
 * semicolon, xlsx, dd/mm/yyyy, the "# wajib diisi" comment line) is the
 * regression harness that proves the move was faithful.
 *
 * The parent+lines importer added four numeric casts. They are here and not in
 * that importer for the same reason: money and koefisien resolve separators by
 * DIFFERENT rules, and a second copy of only one of them is a 1000x error.
 */
class SpreadsheetReader
{
    /** Rows per file. Past this the request, not the import, is the problem. */
    public const MAX_ROWS = 5000;

    /** ~6.8 MB of base64 for a 5 MB file. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'xls'];

    /**
     * Physical rows a file may carry before the reader stops — csv and workbook
     * alike; see readCsv() and readWorkbook().
     *
     * Deliberately far above MAX_ROWS, because the two bound different things:
     * MAX_ROWS caps the rows that ALLOCATE (a document, a line, an error),
     * while this caps the memory the parser walks. A real RAB carries one SUB
     * TOTAL row per section plus a rekapitulasi block and a banner — a
     * 4.200-item bill can be 5.100 physical rows and is an ordinary file. A
     * margin of 50 refused it at the door.
     */
    private const MAX_PHYSICAL_ROWS = 20000;

    /**
     * Columns a workbook may carry. No import template has thirty, let alone
     * two hundred; the bound exists so a sheet whose last used cell is at ZZ1
     * cannot make every row two hundred cells wide in memory.
     */
    private const MAX_COLUMNS = 256;

    /**
     * What an .xlsx may unpack to.
     *
     * A 5.000-row bill of quantities is a few MB of Excel XML, so this is an
     * order of magnitude of headroom — and it is the backstop for a file whose
     * compression ratio is the point: 5 MB of zip can carry hundreds of MB of
     * <row> elements, and the reader would spend minutes on XML the row filter
     * below then throws away.
     */
    private const MAX_UNCOMPRESSED = 64 * 1024 * 1024;

    /**
     * Cell openers Excel would run as a formula.
     *
     * csvCell() prefixes an apostrophe to them on the way out and unguardCell()
     * takes it off on the way back in. They are one rule and must stay one list:
     * guarding on write without unguarding on read rewrites the data.
     */
    private const FORMULA_OPENERS = "=+-@\t\r";

    /**
     * An uploaded file as a raw grid of cells, header row included.
     *
     * @return array<int, array<int, mixed>>
     */
    public function grid(string $filename, string $content): array
    {
        $binary = $this->decode($content);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new LogicException('Format berkas tidak didukung. Gunakan .csv, .xlsx, atau .xls.');
        }

        $rows = $extension === 'csv' ? $this->readCsv($binary) : $this->readWorkbook($binary, $extension);

        if ($rows === []) {
            throw new LogicException('Berkas kosong.');
        }

        return $rows;
    }

    /**
     * Column heading => its index in the row, headings lowercased and trimmed.
     *
     * @return array<string, int>
     */
    public function positions(array $headerRow): array
    {
        $positions = [];

        foreach ($headerRow as $index => $cell) {
            $name = strtolower(trim((string) $cell));

            if ($name !== '') {
                $positions[$name] = $index;
            }
        }

        return $positions;
    }

    /**
     * Which row of a document sheet is the header row.
     *
     * An estimator's RAB opens with a banner — "RENCANA ANGGARAN BIAYA", the
     * project name, the location — before the table starts, so the header is
     * not row 0 and array_shift() would take a title for a column list. The scan
     * looks for a row carrying every name it was told to expect and never for a
     * statistical shape: guessing is exactly what the delimiter bug cost us.
     *
     * @param  array<int, string>  $required  headings that row must carry
     */
    public function locateHeader(array $grid, array $required, int $scan = 20): int
    {
        $limit = min(count($grid), $scan);

        for ($index = 0; $index < $limit; $index++) {
            $names = array_map(
                fn ($cell) => strtolower(trim((string) $cell)),
                array_values((array) $grid[$index]),
            );

            if (array_diff($required, $names) === []) {
                return $index;
            }
        }

        $named = implode(' dan ', array_map(fn (string $name) => "\"{$name}\"", $required));

        throw new LogicException("Baris judul kolom tidak ditemukan — pastikan ada satu baris berisi kolom {$named}.");
    }

    /**
     * CSV is read here rather than through PhpSpreadsheet.
     *
     * Its CSV reader sniffs the delimiter across the WHOLE file, so one line
     * containing spaces — the template's own "# wajib diisi: kode, nama" hint —
     * makes it decide the file is space-separated and hand back one column.
     * Taking the delimiter from the header line alone is both deterministic and
     * correct: the header is the one row whose shape we already know.
     *
     * The BODY is then parsed as a stream rather than line by line, and blank
     * lines are kept. Both halves are corrections, not tidying:
     *
     *   A quoted cell may CONTAIN a newline — "Kamera IP dome 4MP\n(termasuk
     *   bracket)" is an ordinary paste out of Word, and our own export writes it
     *   back correctly quoted. Splitting on newlines first cut that cell in two
     *   before any parser saw it, so one logical row became two physical ones,
     *   every column after it shifted, and re-importing our own export refused
     *   rows whose cells were plainly filled. fgetcsv is the parser that knows a
     *   quoted newline is not a row break.
     *
     *   Dropping blank lines made the grid index stop being the file's line
     *   number, and the caller reports "baris N" from that index. One blank
     *   separator row between two documents — normal in a multi-document
     *   workbook — pointed every later refusal at the wrong row, and the whole
     *   per-line reporting design is only actionable if the number is right.
     *
     * @return array<int, array<int, string>>
     */
    private function readCsv(string $binary): array
    {
        // Excel writes a UTF-8 BOM. Left in place it becomes part of the first
        // column's name and "kode" stops matching.
        $binary = preg_replace('/^\xEF\xBB\xBF/', '', $binary) ?? $binary;
        // One line ending from here on: an old CR-only export still reads, and
        // the physical line count below is a plain count of "\n".
        $binary = str_replace(["\r\n", "\r"], "\n", $binary);

        /*
         * The same physical ceiling the workbook path gets, applied before a
         * single row is built.
         *
         * MAX_PHYSICAL_ROWS is enforced by an IReadFilter, and a filter is a
         * PhpSpreadsheet concept — so csv was bounded by MAX_BYTES alone. 5 MB
         * of csv is a great many lines: a 1,8 MB file of 80.000 `abaikan` rows
         * previewed with no refusal at all, and 2,1 MB of 150.001 lines was
         * read into memory whole. The 5.000-record cap in the importer cannot
         * stand in for this one, because `abaikan` rows are exempt from it by
         * design so a real RAB's subtotals do not spend the record budget.
         *
         * A quoted cell containing newlines still costs its physical lines,
         * which is right: the loop below pads the grid out to them so that
         * "baris N" keeps meaning line N of the file.
         */
        $lines = substr_count($binary, "\n") + (str_ends_with($binary, "\n") ? 0 : 1);

        if ($lines > self::MAX_PHYSICAL_ROWS) {
            throw new LogicException($this->physicalRowCapMessage());
        }

        $break = strpos($binary, "\n");
        $header = $break === false ? $binary : substr($binary, 0, $break);

        $delimiter = ',';
        $best = substr_count($header, ',');

        foreach ([';' => substr_count($header, ';'), "\t" => substr_count($header, "\t")] as $candidate => $count) {
            if ($count > $best) {
                $delimiter = $candidate;
                $best = $count;
            }
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new LogicException('Isi berkas tidak dapat dibaca.');
        }

        fwrite($handle, $binary);
        rewind($handle);

        $rows = [];
        $offset = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // The workbook path drops everything past column 256 in its read
            // filter; csv had no equivalent, so a 4,7 MB file of 260-column
            // rows built 178 MB of PHP arrays out of cells no definition can
            // name. Truncating rather than refusing matches the filter: a
            // sheet with junk out to column ZZ still imports, it just does not
            // pay for it.
            $rows[] = count($row) > self::MAX_COLUMNS
                ? array_slice($row, 0, self::MAX_COLUMNS)
                : $row;

            // How many physical lines that one record ate. A record spanning
            // three lines is padded out to three grid entries so the index of
            // every row after it is still its own line in the file.
            $position = (int) ftell($handle);
            $consumed = substr_count(substr($binary, $offset, $position - $offset), "\n");
            $offset = $position;

            for ($extra = 1; $extra < $consumed; $extra++) {
                $rows[] = [];
            }
        }

        fclose($handle);

        // Blank lines are rows now, so a file of nothing but blank lines would
        // otherwise reach locateHeader and be reported as a missing header row
        // rather than as what it is.
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    return $rows;
                }
            }
        }

        return [];
    }

    /**
     * A workbook, bounded BEFORE it is built rather than after.
     *
     * The row limit used to be applied by the caller to a grid PhpSpreadsheet
     * had already materialised in full, so the disclosed "5.000 baris" bounded
     * nothing in resource terms: a perfectly valid 901 KB .xlsx of 120.000 rows
     * — comfortably inside the 5 MB upload cap — expanded to 136 MB of PHP
     * arrays and held a worker for 23 seconds before anything counted a row.
     * Extrapolated to the cap that is roughly 0,8 GB, which on a normal
     * memory_limit is a fatal error with no JSON body at all.
     *
     * The read filter refuses those cells instead of counting them afterwards,
     * and the file is refused with the same Indonesian message the row cap uses
     * so an operator sees one rule, not two.
     *
     * @return array<int, array<int, mixed>>
     */
    private function readWorkbook(string $binary, string $extension): array
    {
        // Excel reads from a path, not a string. The temp file goes even when the
        // reader throws on a corrupt workbook.
        $path = tempnam(sys_get_temp_dir(), 'erpimp_').'.'.$extension;

        $filter = new class(self::MAX_PHYSICAL_ROWS, self::MAX_COLUMNS) implements IReadFilter
        {
            public bool $exceeded = false;

            public function __construct(private readonly int $rows, private readonly int $columns) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                if ($row > $this->rows) {
                    // Remembered, not thrown: PhpSpreadsheet calls this from
                    // inside its own parse and an exception here would surface
                    // as "Berkas tidak dapat dibaca" instead of the row cap.
                    $this->exceeded = true;

                    return false;
                }

                return Coordinate::columnIndexFromString($columnAddress) <= $this->columns;
            }
        };

        try {
            file_put_contents($path, $binary);
            $this->assertUnpacksToASaneSize($path, $extension);

            $sheets = Excel::toArray(new class($filter) implements WithReadFilter
            {
                public function __construct(private readonly IReadFilter $filter) {}

                public function readFilter(): IReadFilter
                {
                    return $this->filter;
                }
            }, $path);
        } catch (LogicException $e) {
            // Our own refusal, already in the operator's words.
            throw $e;
        } catch (\Throwable $e) {
            throw new LogicException('Berkas tidak dapat dibaca: '.$e->getMessage());
        } finally {
            @unlink($path);
        }

        if ($filter->exceeded) {
            throw new LogicException($this->physicalRowCapMessage());
        }

        return $sheets[0] ?? [];
    }

    /**
     * Refuse an .xlsx that unpacks to far more than it weighs.
     *
     * The read filter above bounds the ARRAYS; this bounds the XML the parser
     * has to walk to reach them, which the filter cannot. A zip is free to
     * claim any expansion ratio it likes and the 5 MB upload cap says nothing
     * about it.
     */
    private function assertUnpacksToASaneSize(string $path, string $extension): void
    {
        if ($extension !== 'xlsx' || ! class_exists(\ZipArchive::class)) {
            return;
        }

        $zip = new \ZipArchive;

        // A file that will not open as a zip is the reader's to describe, not
        // ours: it produces a message naming the real fault.
        if ($zip->open($path) !== true) {
            return;
        }

        $uncompressed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $uncompressed += (int) ($zip->statIndex($index)['size'] ?? 0);
        }

        $zip->close();

        if ($uncompressed > self::MAX_UNCOMPRESSED) {
            throw new LogicException(sprintf(
                'Isi berkas .xlsx terbuka menjadi %s MB setelah dibuka dan tidak diproses. Bagi berkas menurut dokumen.',
                number_format($uncompressed / 1048576, 0, ',', '.'),
            ));
        }
    }

    /** The record bound: rows that create a document, a line, or an error. */
    public function rowCapMessage(): string
    {
        return 'Berkas melebihi '.number_format(self::MAX_ROWS, 0, ',', '.')
            .' baris isi. Bagi berkas menurut dokumen.';
    }

    /**
     * The physical bound. Named separately from rowCapMessage() so the operator
     * is told which ceiling they hit — "5.000 baris isi" on a sheet of 18.000
     * physical rows reads like a lie, and sends them splitting the wrong thing.
     */
    public function physicalRowCapMessage(): string
    {
        return 'Berkas melebihi '.number_format(self::MAX_PHYSICAL_ROWS, 0, ',', '.')
            .' baris (termasuk baris subtotal, judul, dan baris kosong). '
            .'Bagi berkas menurut dokumen.';
    }

    private function decode(string $content): string
    {
        if (str_contains($content, ',') && str_starts_with($content, 'data:')) {
            $content = substr($content, strpos($content, ',') + 1);
        }

        $binary = base64_decode($content, true);

        if ($binary === false) {
            throw new LogicException('Isi berkas tidak dapat dibaca.');
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw new LogicException('Berkas melebihi 5 MB.');
        }

        return $binary;
    }

    /**
     * A cell any spreadsheet reads back as one field.
     *
     * Also refuses to hand back a cell starting with =, +, - or @: Excel treats
     * those as formulas, so an exported vendor name of "=cmd|..." becomes code
     * the moment somebody opens the file. Prefixing an apostrophe keeps it text.
     */
    public function csvCell(string $value): string
    {
        /*
         * Escape an apostrophe the VALUE itself owns, not just formula openers.
         * Without this the pair is not an inverse: a description stored as
         * "'- Galian" exports verbatim, and unguardCell then eats the
         * apostrophe the estimator typed. One export/import round trip must
         * return the same string it started with, always.
         */
        if ($value !== '' && (str_contains(self::FORMULA_OPENERS, $value[0]) || $value[0] === "'")) {
            $value = "'".$value;
        }

        return str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")
            ? '"'.str_replace('"', '""', $value).'"'
            : $value;
    }

    /**
     * The inverse of csvCell's formula guard, applied to every cell we read.
     *
     * The apostrophe csvCell writes is Excel's own display-only text marker —
     * Excel never puts it in the cell's VALUE — so nothing on the read side
     * used to take it off again, and one export/import round trip permanently
     * rewrote the data it exists to preserve: '- Pembersihan lahan' came back
     * stored as "'- Pembersihan lahan". Dash-led bullet descriptions are
     * ubiquitous in an Indonesian BOQ, "export, edit in Excel, import back" is
     * the entire reason the export endpoint exists, and for AHSP it hit the
     * group column itself — an analysis whose code began with '-' no longer
     * matched its own record and would have been CREATED a second time.
     *
     * An exported NEGATIVE amount went the same way: "'-1.250.000" is not a
     * number, so the line was refused for a fault of the writer's.
     */
    public function unguardCell(string $value): string
    {
        // Exact inverse of csvCell: strip ONE leading apostrophe, and only when
        // what follows is what csvCell would have guarded (a formula opener or
        // an apostrophe it doubled).
        return strlen($value) > 1 && $value[0] === "'"
            && (str_contains(self::FORMULA_OPENERS, $value[1]) || $value[1] === "'")
            ? substr($value, 1)
            : $value;
    }

    // ----------------------------------------------------------------- casts

    /**
     * @throws InvalidArgumentException naming the offending text, so the caller
     *                                  can prefix it with the column heading
     */
    public function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int' => $this->castNumber($value, true),
            'decimal' => $this->castNumber($value, false),
            'bool' => $this->castBool($value),
            'text' => $this->castText($value),
            'date' => $this->castDate($value),
            // Money and volume group their dots; a koefisien and a percentage
            // never do. See castAmount() — this split is the whole reason there
            // are four numeric casts instead of one.
            'money' => $this->castAmount($value, 2, true),
            'qty' => $this->castAmount($value, 3, true),
            'coefficient' => $this->castAmount($value, 6, false),
            'percent' => $this->castAmount($value, 4, false),
            default => $value,
        };
    }

    private function castNumber(mixed $value, bool $integer): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $integer ? (int) $value : (float) $value;
        }

        // Indonesian sheets write 1.250.000,50. Strip the thousands dots first,
        // then turn the decimal comma into a point — the other order destroys it.
        $text = str_replace(' ', '', (string) $value);

        if (str_contains($text, ',')) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }

        if (! is_numeric($text)) {
            throw new InvalidArgumentException("\"{$value}\" bukan angka.");
        }

        return $integer ? (int) round((float) $text) : (float) $text;
    }

    /**
     * A number off an estimator's sheet, with the separator rule that column needs.
     *
     * $groupedDotsAreThousands is the whole point of having four casts instead of
     * one, and the line it draws is between what is COUNTED and what is a RATIO.
     *
     * Counted: money and volume. "1.250" in a HARGA SATUAN column is one thousand
     * two hundred and fifty, and "1.500" in a VOLUME column is fifteen hundred
     * square metres — an estimator's sheet groups both, and 1.500 m2 of site
     * clearing is on the first page of every RAB in the country. Only a
     * strict 1-3/3/3 grouping counts as thousands, so a genuine "1.5" or "0.75"
     * is still a decimal.
     *
     * A ratio: koefisien and percent. "1.050" in a KOEFISIEN column is
     * one-point-nought-five — the AHSP book writes coefficients to three or six
     * decimals, never groups them, and a coefficient of a thousand does not
     * exist. Reading 1,05 as 1050 multiplies the unit price of every BOQ item
     * using that analysis by a thousand, and the BOQ still adds up, so nothing
     * downstream would catch it. More than one dot there is refused outright.
     *
     * Nothing here can return 0 for a cell it failed to understand: an
     * unparseable cell throws, which refuses the line and therefore the document.
     */
    private function castAmount(mixed $value, int $scale, bool $groupedDotsAreThousands): float
    {
        // A real number cell in a workbook carries no separator ambiguity at all.
        if (is_int($value) || is_float($value)) {
            return round((float) $value, $scale);
        }

        $text = trim((string) $value);

        // NBSP and thin space are what a copy-paste out of a PDF leaves behind.
        $text = str_replace(["\u{00A0}", "\u{2009}", "\u{202F}", ' ', "\t"], '', $text);

        $sign = 1.0;

        // Accountants write a negative as (1.250.000).
        if (preg_match('/^\((.*)\)$/', $text, $matches) === 1) {
            $sign = -1.0;
            $text = $matches[1];
        }

        $text = (string) preg_replace('/^(?:rp|idr)\.?/i', '', $text);
        // "1.250.000,-" is a complete Indonesian amount, not a malformed one.
        $text = (string) preg_replace('/(?:,-|,--)$/', '', $text);
        $text = (string) preg_replace('/(?:idr|rp|%)$/i', '', $text);

        if (str_starts_with($text, '-')) {
            $sign *= -1.0;
            $text = substr($text, 1);
        } elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }

        $hasDot = str_contains($text, '.');
        $hasComma = str_contains($text, ',');

        if ($hasDot && $hasComma) {
            // Both present: the RIGHTMOST is the decimal mark, whichever it is.
            // 1.234.567,89 and 1,234,567.89 are the same number.
            $decimal = strrpos($text, '.') > strrpos($text, ',') ? '.' : ',';
            $text = str_replace($decimal === '.' ? ',' : '.', '', $text);
            $text = str_replace($decimal, '.', $text);
        } elseif ($hasComma) {
            // One comma is a decimal mark; several can only be thousands.
            $text = substr_count($text, ',') === 1
                ? str_replace(',', '.', $text)
                : str_replace(',', '', $text);
        } elseif ($hasDot) {
            // The first group can never be zero-led. No Indonesian sheet writes
            // seven hundred and fifty as "0.750", but an English-locale export
            // writes three quarters of a cubic metre exactly that way — and
            // \d{1,3} matched it, storing 750 m3 of concrete for 0,75 and a BOQ
            // that foots perfectly at a thousand times the money. Excluding a
            // leading zero costs the deliberate "1.500 m2 = 1500" rule nothing.
            if ($groupedDotsAreThousands && preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $text) === 1) {
                $text = str_replace('.', '', $text);
            } elseif (! $groupedDotsAreThousands && substr_count($text, '.') > 1) {
                throw new InvalidArgumentException(
                    "\"{$value}\" memiliki lebih dari satu tanda desimal; tulis koefisien seperti 1,05.",
                );
            }
        }

        if ($text === '' || ! is_numeric($text)) {
            throw new InvalidArgumentException("\"{$value}\" bukan angka.");
        }

        return round($sign * (float) $text, $scale);
    }

    /**
     * Identifiers that are digits but are not numbers.
     *
     * A 16-digit NIK, an account number with leading zeros, a barcode — every
     * spreadsheet reads these as numeric, and PHP then stringifies large ones in
     * scientific notation. Without this, a valid NIK arrives as 3.2010101019E+15
     * and the row is refused for a fault of the reader's.
     */
    private function castText(mixed $value): string
    {
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    private function castBool(mixed $value): bool
    {
        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'ya', 'y', 'yes', 'aktif'], true);
    }

    private function castDate(mixed $value): string
    {
        // Excel hands back a serial number for a real date cell.
        if (is_numeric($value) && ! str_contains((string) $value, '-') && ! str_contains((string) $value, '/')) {
            return Carbon::createFromTimestamp(((float) $value - 25569) * 86400)->toDateString();
        }

        $text = trim((string) $value);

        // dd/mm/yyyy is what an Indonesian sheet contains, and it is exactly the
        // format strtotime reads as mm/dd/yyyy. Converted explicitly so 03/04/2026
        // is 3 April, not 4 March.
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            $parsed = \DateTime::createFromFormat($format.'|', $text);

            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        throw new InvalidArgumentException("\"{$text}\" bukan tanggal (gunakan dd/mm/yyyy atau yyyy-mm-dd).");
    }
}
