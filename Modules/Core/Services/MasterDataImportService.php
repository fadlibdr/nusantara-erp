<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Support\ImportableResources;
use Modules\Core\Support\SpreadsheetReader;

/**
 * Bulk load and bulk export of master data.
 *
 * maatwebsite/excel shipped in composer.json from the first commit and was called
 * nowhere. ProductionSeeder lays down a chart of accounts and two category trees
 * and stops: items 0, employees 0, vendors 0, customers 0. Loading 2.000 items
 * meant filling in 2.000 forms, and every other document in the system points at
 * one of these four tables — so a go-live could not start.
 *
 * Three decisions are load-bearing:
 *
 * PREVIEW IS SEPARATE FROM COMMIT. The same file is parsed and validated twice,
 * once to show what would happen and once to do it. A bulk import that reports
 * its errors only after writing half of them is worse than no import.
 *
 * A ROW EITHER LANDS OR IT DOES NOT. Validation is per row, and the commit runs
 * in one transaction that only covers the rows that passed. A single bad row does
 * not abandon 1.999 good ones — but neither is it half-written.
 *
 * MATCHING IS ON THE BUSINESS CODE. An existing code is UPDATED, not duplicated.
 * That makes re-running a corrected file safe, which is what people actually do:
 * import, read the errors, fix the sheet, import again.
 *
 * The export exists for the same reason. "Tidak ada ekspor data master juga" —
 * and the fastest way to bulk-EDIT a thousand rows is to export them, change the
 * sheet, and import it back.
 */
class MasterDataImportService
{
    /**
     * Decoding, the CSV delimiter, the workbook reader and every cast live in
     * SpreadsheetReader, shared with the parent+lines importer. They were moved
     * there rather than copied: readCsv() and castText() each carry a fix for a
     * bug that cost a day to find, and a second copy would not have carried them.
     */
    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * The header row a person should start from, as CSV.
     *
     * Required columns are marked in a second commented line rather than in the
     * header itself, because the header has to match exactly on the way back in.
     */
    public function template(string $resource): string
    {
        $definition = ImportableResources::definition($resource);
        $headers = array_column($definition['columns'], 'header');

        $required = array_column(
            array_filter($definition['columns'], fn ($column) => $column['required'] ?? false),
            'header',
        );

        return implode(',', $headers)."\n"
            .'# wajib diisi: '.implode(', ', $required)."\n";
    }

    /**
     * Parse and validate without writing anything.
     *
     * @return array{resource: string, columns: array, rows: array, summary: array}
     */
    public function preview(string $resource, string $filename, string $content): array
    {
        $definition = ImportableResources::definition($resource);
        $file = $this->read($filename, $content, $definition);

        $existing = $this->existingKeys($definition);
        $seen = [];
        $prepared = [];

        foreach ($file['rows'] as $index => $raw) {
            $prepared[] = $this->prepare($definition, $raw, $index + 2, $existing, $seen, $file['headers']);
        }

        return [
            'resource' => $resource,
            'label' => $definition['label'],
            'columns' => array_column($definition['columns'], 'header'),
            'rows' => $prepared,
            'summary' => $this->summarise($prepared),
        ];
    }

    /**
     * Write every row that passes; report every row that does not.
     *
     * @return array{created: int, updated: int, skipped: int, rows: array, summary: array}
     */
    public function commit(string $resource, string $filename, string $content): array
    {
        $preview = $this->preview($resource, $filename, $content);
        $definition = ImportableResources::definition($resource);
        $model = $definition['model'];
        $unique = $definition['unique'];

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($preview, $model, $unique, &$created, &$updated): void {
            foreach ($preview['rows'] as $row) {
                if (! $row['valid']) {
                    continue;
                }

                $key = $row['values'][$unique];
                $record = $model::query()->where($unique, $key)->first();

                if ($record === null) {
                    $model::query()->create($row['values']);
                    $created++;
                } else {
                    $record->fill($row['values'])->save();
                    $updated++;
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $preview['summary']['invalid'],
            'rows' => array_values(array_filter($preview['rows'], fn ($row) => ! $row['valid'])),
            'summary' => $preview['summary'],
        ];
    }

    /**
     * Every row of a master table, as CSV, in exactly the shape the importer
     * accepts back. Export → edit → import is the bulk-edit path.
     */
    public function export(string $resource): string
    {
        $definition = ImportableResources::definition($resource);
        $model = $definition['model'];

        $out = implode(',', array_column($definition['columns'], 'header'))."\n";

        foreach ($model::query()->orderBy($definition['unique'])->cursor() as $record) {
            $cells = [];

            foreach ($definition['columns'] as $column) {
                $cells[] = $this->csvCell($this->exportValue($record, $column));
            }

            $out .= implode(',', $cells)."\n";
        }

        return $out;
    }

    // ------------------------------------------------------------------ read

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    private function read(string $filename, string $content, array $definition): array
    {
        $rows = $this->reader->grid($filename, $content);

        // A flat sheet's header is its first NON-EMPTY row, and array_shift is
        // right here: there is no banner to scan past, and every existing export
        // starts with the header. The document importer, whose files open with a
        // merged title block, is the one that has to go looking.
        //
        // The blank lines are skipped explicitly because the reader now keeps
        // them — it has to, so that the document importer can report the file's
        // real line numbers — and a sheet that opens with one empty row would
        // otherwise have that row read as its column list.
        while ($rows !== [] && $this->blankRow($rows[0] ?? [])) {
            array_shift($rows);
        }

        $headerRow = array_shift($rows);

        return [
            'headers' => $this->presentHeaders($headerRow, $definition),
            'rows' => $this->mapHeaders($headerRow, $rows, $definition),
        ];
    }

    private function blankRow(mixed $row): bool
    {
        foreach ((array) $row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Which of the known headers this file actually carries.
     *
     * The distinction matters on update: a partial sheet ("just fix everyone's
     * bank account") must leave every column it does not mention alone, and
     * several of these columns are NOT NULL in the schema, so writing them as
     * null fails the insert instead of producing a row message.
     */
    private function presentHeaders(array $headerRow, array $definition): array
    {
        $names = array_map(fn ($cell) => strtolower(trim((string) $cell)), $headerRow);

        return array_values(array_intersect(array_column($definition['columns'], 'header'), $names));
    }

    /**
     * Headers are matched, never guessed by position.
     *
     * A file whose columns are in a different order than the template is the
     * normal case — somebody exported from their old system. A file missing a
     * required column is refused up front, with the column named, rather than
     * producing 2.000 identical row errors.
     */
    private function mapHeaders(array $headerRow, array $rows, array $definition): array
    {
        $positions = $this->reader->positions($headerRow);
        $known = array_column($definition['columns'], 'header');
        $missing = [];

        foreach ($definition['columns'] as $column) {
            if (($column['required'] ?? false) && ! isset($positions[$column['header']])) {
                $missing[] = $column['header'];
            }
        }

        if ($missing !== []) {
            throw new LogicException('Kolom wajib tidak ditemukan di berkas: '.implode(', ', $missing).'.');
        }

        $mapped = [];

        // The identity column, which is required, so a real data row always has
        // it — and the template's own "# wajib diisi" hint line does not, since
        // its text lands in whichever cell the comma split puts it in.
        // `unique` names the MODEL field ('code'); the sheet calls it something
        // else ('kode'), so it has to be mapped through the column list.
        $identityHeader = null;

        foreach ($definition['columns'] as $column) {
            if ($column['field'] === $definition['unique']) {
                $identityHeader = $column['header'];
                break;
            }
        }

        $identity = $identityHeader !== null ? ($positions[$identityHeader] ?? 0) : 0;

        foreach ($rows as $row) {
            // A comment line (the template's own "# wajib diisi" hint) and a
            // fully blank row are both skipped rather than reported as errors.
            //
            // The marker is read from the IDENTITY column, not from the
            // physically first cell. Columns are matched by name, so a file
            // whose first column is `nama` rather than `kode` used to lose any
            // row whose name began with '#' — silently, while every row around
            // it imported. Same defect the document importer carried, same fix:
            // anchor the test to a column the row must have, not to a position
            // the file is free to choose.
            $marker = trim((string) ($row[$identity] ?? ''));

            if (str_starts_with($marker, '#')) {
                continue;
            }

            $values = [];
            $empty = true;

            foreach ($known as $header) {
                $value = isset($positions[$header]) ? $row[$positions[$header]] ?? null : null;
                // Trimmed, then unguarded. This importer's export prefixes an
                // apostrophe to a cell Excel would run as a formula, and its
                // read side never took it off again — so "export, edit, import
                // back", which is what the export exists for, permanently
                // rewrote every name or code beginning with = + - or @. Same
                // bug, same fix, same reader as the document importer.
                $value = is_string($value) ? $this->reader->unguardCell(trim($value)) : $value;
                $values[$header] = ($value === '' ? null : $value);

                if ($values[$header] !== null) {
                    $empty = false;
                }
            }

            if ($empty) {
                continue;
            }

            $mapped[] = $values;

            if (count($mapped) > SpreadsheetReader::MAX_ROWS) {
                throw new LogicException('Berkas melebihi '.number_format(SpreadsheetReader::MAX_ROWS, 0, ',', '.').' baris. Bagi menjadi beberapa berkas.');
            }
        }

        return $mapped;
    }

    // -------------------------------------------------------------- validate

    /**
     * One row: cast, validate, and say what it would do.
     *
     * @param  array<string, int>  $existing  business key => id already in the table
     * @param  array<string, int>  $seen  business key => line number earlier in THIS file
     */
    private function prepare(array $definition, array $raw, int $line, array $existing, array &$seen, array $present): array
    {
        $values = [];
        $errors = [];
        $rules = [];

        foreach ($definition['columns'] as $column) {
            $header = $column['header'];
            $value = $raw[$header] ?? null;

            if ($value === null && array_key_exists('default', $column)) {
                $value = $column['default'];
            }

            if ($value !== null && isset($column['cast'])) {
                try {
                    $value = $this->cast($column['cast'], $value);
                } catch (InvalidArgumentException $e) {
                    $errors[] = "{$header}: {$e->getMessage()}";
                    $value = null;
                }
            }

            if ($value !== null && isset($column['lookup'])) {
                [$table, $lookupColumn] = $column['lookup'];
                $id = DB::table($table)->where($lookupColumn, $value)->value('id');

                if ($id === null) {
                    $errors[] = "{$header}: \"{$value}\" tidak ditemukan.";
                }

                $value = $id;
            }

            if (($column['required'] ?? false) && ($value === null || $value === '')) {
                $errors[] = "{$header}: wajib diisi.";
            }

            // A column the FILE does not carry is left alone rather than written
            // as null: a partial sheet ("just fix everyone's bank account") must
            // not blank every other field, and several of these columns are NOT
            // NULL in the schema with no database default.
            if ($value !== null || in_array($header, $present, true)) {
                $values[$column['field']] = $value;
            }

            if (($column['rules'] ?? []) !== [] && ! isset($column['lookup'])) {
                $rules[$column['field']] = array_merge(['nullable'], $column['rules']);
            }
        }

        $validator = Validator::make($values, $rules, [], $this->attributeNames($definition));

        foreach ($validator->errors()->all() as $message) {
            $errors[] = $message;
        }

        $key = (string) ($values[$definition['unique']] ?? '');

        // A file that lists the same code twice is a mistake worth naming. Left
        // alone, the second row would silently overwrite the first and the
        // importer would report two successes for one record.
        if ($key !== '' && isset($seen[$key])) {
            $errors[] = "kode \"{$key}\" muncul dua kali dalam berkas ini (baris {$seen[$key]}).";
        } elseif ($key !== '') {
            $seen[$key] = $line;
        }

        return [
            'line' => $line,
            'key' => $key,
            'action' => $key !== '' && isset($existing[$key]) ? 'update' : 'create',
            'valid' => $errors === [],
            'errors' => $errors,
            'values' => $values,
        ];
    }

    private function attributeNames(array $definition): array
    {
        $names = [];

        foreach ($definition['columns'] as $column) {
            $names[$column['field']] = $column['header'];
        }

        return $names;
    }

    private function cast(string $type, mixed $value): mixed
    {
        return $this->reader->cast($type, $value);
    }

    /** @return array<string, int> */
    private function existingKeys(array $definition): array
    {
        $model = $definition['model'];

        return $model::query()
            ->pluck('id', $definition['unique'])
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function summarise(array $rows): array
    {
        $valid = array_filter($rows, fn ($row) => $row['valid']);

        return [
            'total' => count($rows),
            'valid' => count($valid),
            'invalid' => count($rows) - count($valid),
            'to_create' => count(array_filter($valid, fn ($row) => $row['action'] === 'create')),
            'to_update' => count(array_filter($valid, fn ($row) => $row['action'] === 'update')),
        ];
    }

    // ---------------------------------------------------------------- export

    private function exportValue($record, array $column): string
    {
        if (isset($column['lookup'])) {
            [$table, $lookupColumn] = $column['lookup'];
            $id = $record->{$column['field']};

            return $id === null ? '' : (string) DB::table($table)->where('id', $id)->value($lookupColumn);
        }

        $value = $record->{$column['field']};

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'ya' : 'tidak';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) (is_object($value) && enum_exists($value::class) ? $value->value : $value);
    }

    /** Quoting and the =/+/-/@ formula guard live in the shared reader. */
    private function csvCell(string $value): string
    {
        return $this->reader->csvCell($value);
    }
}
