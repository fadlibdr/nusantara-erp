<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Core\Support\ImportableDocuments;
use Modules\Core\Support\SpreadsheetReader;

/**
 * Bulk load of documents that are a PARENT PLUS LINES.
 *
 * MasterDataImportService is single-table by construction — one row in the file
 * is one $model::where($unique, $key) — and penawaran, BOQ, AHSP and RAP are not.
 * A BOQ is a header, then sections, then work items under them; an AHSP is an
 * analysis and its koefisien components. Bolting a second mode onto the flat
 * importer would put two importers in one class that the whole suite leans on,
 * so this is a sibling. What they genuinely share — decoding, the CSV delimiter
 * taken from the header line, the workbook reader, every cast — was MOVED into
 * SpreadsheetReader, so those fixes exist exactly once.
 *
 * Four decisions are load-bearing:
 *
 * A DOCUMENT IS ALL-OR-NOTHING. One bad line refuses its whole document. That is
 * the parent+lines analogue of the flat importer's "a row either lands or it does
 * not", and it is the single most important difference between them: a BOQ that
 * silently drops three lines is a BOQ that is wrong forever, and every variance
 * report written against it is wrong too.
 *
 * THE FILE IS PER-DOCUMENT. Each document commits in its own transaction, so one
 * refused document does not abandon the eleven good ones beside it — an ELV job
 * arrives as twelve branches in one workbook. The one exception is a row that
 * cannot be attributed to any document at all (a mistyped `tipe` before the first
 * dokumen row): that refuses the FILE, because such a row may belong to any of
 * them and guessing is how lines get mis-filed.
 *
 * PREVIEW IS SEPARATE FROM COMMIT and writes nothing. The same file is parsed
 * twice, once to show what would happen and once to do it.
 *
 * THE IMPORTER NEVER TOUCHES A MODEL. It assembles exactly the payload the
 * module's FormRequest describes, validates with that request's own rules(), and
 * hands it to the module's own service — so recomputeTotals, recalcUnitPrice,
 * recalcTotals, the wholesale line replacement and every status guard apply
 * unchanged, and there is no second definition of what a valid BOQ is.
 */
class DocumentImportService
{
    /**
     * Below a rupiah, or below half a percent, a difference between the sheet's
     * own JUMLAH column and qty x harga is rounding. Above it, we misread a cell.
     */
    private const CHECKSUM_ABSOLUTE = 1.0;

    private const CHECKSUM_RATIO = 0.005;

    /** Decimal places the export writes per numeric cast; see exportNumber(). */
    private const EXPORT_SCALES = ['money' => 2, 'qty' => 3, 'coefficient' => 6, 'percent' => 4, 'decimal' => 3];

    /** @var array<string, bool> "table.column" => does that column exist */
    private array $columns = [];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ImportableDocuments $registry,
    ) {}

    /**
     * The sheet a person should start from.
     *
     * The worked example rows ship commented out with a '#' in the `tipe`
     * column: a row whose `tipe` cell starts with '#' is skipped, so the
     * operator activates the example by deleting one character instead of
     * retyping it. `tipe` is written first because that is where a person looks
     * for it, not because the skip depends on the position — records() reads
     * that column by name, exactly like every other.
     */
    public function template(string $resource): string
    {
        $definition = $this->registry->definition($resource);
        $headers = $this->headers($definition);

        $out = implode(',', $headers)."\n";

        $words = [];

        foreach ($definition['rows'] as $type => $row) {
            $words[] = "{$type} = {$row['label']}";
        }

        $words[] = ImportableDocuments::SKIP.' = baris subtotal/rekap yang tidak ikut diimpor';

        $out .= '# '.ImportableDocuments::TYPE_COLUMN.': '.implode(' | ', $words)."\n";

        foreach ($definition['rows'] as $type => $row) {
            $required = array_column(
                array_filter($row['columns'], fn (array $column) => $column['required'] ?? false),
                'header',
            );

            if ($row['role'] === 'header') {
                array_unshift($required, $definition['group']);
            }

            if ($required !== []) {
                $out .= "# wajib pada baris {$type}: ".implode(', ', array_unique($required))."\n";
            }
        }

        // What a blank cell means is the difference between "I am not changing
        // this" and "delete what is stored", and a spreadsheet cannot say which
        // — so the template does, generated from the definition so it cannot
        // drift away from what resolveRow() actually does.
        $clearable = [];

        foreach ($definition['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                if (isset($column['field']) && ($column['blank'] ?? 'keep') === 'clear') {
                    $clearable[] = $column['header'];
                }
            }
        }

        $out .= '# pada dokumen yang SUDAH ADA: sel kosong tidak mengubah nilai tersimpan'
            .($clearable === [] ? '' : ' — kecuali '.implode(', ', array_unique($clearable)).', yang justru dikosongkan')
            .", dan kolom yang tidak ada di berkas tidak pernah ditulis.\n";

        foreach ($definition['template_notes'] as $note) {
            $out .= '# '.$note."\n";
        }

        // Without a `jumlah` column there is nothing to check our own number
        // reading against, and 1.250 in a harga column is genuinely ambiguous.
        $out .= "# kolom jumlah tidak disimpan — dipakai untuk memeriksa pembacaan angka. Jangan gabungkan (merge) sel di dalam tabel.\n";

        foreach ($definition['template_example'] as $example) {
            $cells = [];

            foreach ($headers as $header) {
                $value = (string) ($example[$header] ?? '');
                // The marker goes in the TYPE cell, because that is where
                // records() reads it — by name, not by position. It used to be
                // written positionally into column 0, which agreed only
                // because headers() unshifts the type column first; reorder
                // that and every shipped template would have commented out its
                // group column instead, in silence.
                $cells[] = $this->reader->csvCell(
                    $header === ImportableDocuments::TYPE_COLUMN ? '#'.$value : $value,
                );
            }

            $out .= implode(',', $cells)."\n";
        }

        return $out;
    }

    /**
     * What the file would do, without doing it.
     */
    public function preview(string $resource, string $filename, string $content): array
    {
        $result = $this->analyse($resource, $filename, $content);

        // The assembled payloads carry resolved ids and are the commit's business,
        // not the screen's — the preview shows codes, which is what an operator
        // can check.
        $result['documents'] = array_map(function (array $document): array {
            unset($document['payload'], $document['target_model']);

            return $document;
        }, $result['documents']);

        return $result;
    }

    /**
     * Write every document that passes; report every document that does not.
     */
    public function commit(string $resource, string $filename, string $content, ?User $by = null): array
    {
        $definition = $this->registry->definition($resource);
        $result = $this->analyse($resource, $filename, $content);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $codes = [];
        // commit() used to report only the documents it REFUSED, so every
        // warning on a document that was written — "2 baris akan DIHAPUS",
        // "pelanggan penawaran ini berubah dari CUST-002" — was computed, shown
        // in the preview, and then thrown away by the one call that made it
        // true. They are carried out with the result instead.
        $warnings = $result['warnings'];

        foreach ($result['documents'] as $index => $document) {
            // A row nobody could attribute to a document may belong to any of
            // them, so nothing in this file is safe to write.
            if (! $document['valid'] || $result['errors'] !== []) {
                $skipped++;

                continue;
            }

            try {
                // One transaction per DOCUMENT: a line that only the database can
                // refuse rolls back its own document whole, and leaves the others
                // already committed beside it.
                $record = DB::transaction(function () use ($definition, $document, $filename, $by) {
                    $record = $document['action'] === 'update'
                        ? ($definition['update'])($document['target_model'], $document['payload'])
                        : ($definition['create'])($document['payload']);

                    /*
                     * Provenance stamp (P8, the legacy importers). The engine's
                     * own fact, written by the engine: no module service can
                     * know a FILE was involved, and threading the name through
                     * four services' fill lists would teach each of them a
                     * column that is none of their business. saveQuietly —
                     * a marker, not an edit; no observer should mistake it
                     * for one. Inside the document's transaction, so a stamp
                     * that cannot be written rolls its document back rather
                     * than leaving it unmarked.
                     */
                    if ($definition['source_column'] !== null) {
                        $record->forceFill([
                            $definition['source_column'] => mb_substr($filename, 0, 160),
                        ])->saveQuietly();
                    }

                    $this->recordSubmission($record, $by);

                    return $record;
                });

                $codes[$document['group']] = (string) $record->{$definition['code_column']};
                $document['action'] === 'update' ? $updated++ : $created++;

                foreach ($document['warnings'] as $warning) {
                    $warnings[] = "{$document['group']}: {$warning}";
                }
            } catch (\Throwable $e) {
                // The module's service refused it — a status guard, a constraint.
                // Its message is the operator's, and the other documents stand.
                $result['documents'][$index]['valid'] = false;
                $result['documents'][$index]['errors'][] = $e->getMessage();
                $skipped++;
            }
        }

        $result['summary']['refused'] = $skipped;

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'codes' => $codes,
            'errors' => $result['errors'],
            'warnings' => $warnings,
            'documents' => array_values(array_map(
                function (array $document): array {
                    unset($document['payload'], $document['target_model']);

                    return $document;
                },
                array_filter($result['documents'], fn (array $document) => ! $document['valid']),
            )),
            'summary' => $result['summary'],
        ];
    }

    /**
     * Every document, in exactly the shape the importer accepts back.
     *
     * Three jobs: bulk edit (export, change it in Excel, import it back), getting
     * the assigned codes after a create-import so every later upload is an update
     * in place, and being a worked example better than any template.
     *
     * @param  array{kode?: string, status?: string}  $filters
     */
    public function export(string $resource, array $filters = []): string
    {
        $definition = $this->registry->definition($resource);
        $headers = $this->headers($definition);

        $out = implode(',', $headers)."\n";

        foreach ($this->exportable($definition, $filters) as $document) {
            foreach ($this->exportRows($definition, $document) as $row) {
                $cells = [];

                foreach ($headers as $header) {
                    $cells[] = $this->reader->csvCell((string) ($row[$header] ?? ''));
                }

                $out .= implode(',', $cells)."\n";
            }
        }

        return $out;
    }

    // ----------------------------------------------------------------- parse

    /**
     * Read the file, group it into documents, and say what each would do.
     */
    private function analyse(string $resource, string $filename, string $content): array
    {
        $definition = $this->registry->definition($resource);
        $grid = $this->reader->grid($filename, $content);

        $headerIndex = $this->reader->locateHeader($grid, [ImportableDocuments::TYPE_COLUMN, $definition['group']]);
        $positions = $this->reader->positions($grid[$headerIndex]);

        $known = $this->headers($definition);
        $unmapped = array_values(array_diff(array_keys($positions), $known));

        $this->assertRequiredColumns($definition, $positions);

        [$records, $fileErrors] = $this->records($definition, $grid, $headerIndex, $positions);

        $warnings = [];

        if ($unmapped !== []) {
            // The commonest real import failure by a distance: KWANTITAS where
            // the importer wanted volume, silently ignored, BOQ short by a column.
            $warnings[] = 'kolom tidak dikenali dan diabaikan: '.implode(', ', $unmapped).'.';
        }

        if (! $this->hasChecksumColumn($definition, $positions)) {
            $warnings[] = 'kolom jumlah tidak ada, sehingga pembacaan angka tidak dapat diperiksa ulang.';
        }

        $documents = [];

        foreach ($this->group($records) as $group => $rows) {
            $documents[] = $this->document($definition, (string) $group, $rows);
        }

        return [
            'resource' => $resource,
            'label' => $definition['label'],
            'group_column' => $definition['group'],
            'columns' => $known,
            'unmapped_columns' => $unmapped,
            'errors' => $fileErrors,
            'warnings' => $warnings,
            'documents' => $documents,
            'summary' => $this->summarise($documents, $fileErrors),
        ];
    }

    /**
     * The typed rows of the file, in order, with their sheet line numbers.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function records(array $definition, array $grid, int $headerIndex, array $positions): array
    {
        $types = $this->typeWords($definition);
        $records = [];
        $errors = [];
        $currentGroup = null;
        $read = 0;

        for ($index = $headerIndex + 1; $index < count($grid); $index++) {
            $row = array_values((array) $grid[$index]);
            $line = $index + 1;

            $cells = [];
            $empty = true;

            foreach ($positions as $header => $position) {
                $value = $row[$position] ?? null;
                // Trimmed, then unguarded. Our own export writes "'- Galian
                // tanah" so Excel cannot run the cell as a formula; without the
                // inverse here one export/import round trip stored the
                // apostrophe forever. See SpreadsheetReader::unguardCell.
                $value = is_string($value) ? $this->reader->unguardCell(trim($value)) : $value;
                $cells[$header] = ($value === '' ? null : $value);

                if ($cells[$header] !== null) {
                    $empty = false;
                }
            }

            if ($empty) {
                continue;
            }

            $word = strtolower(trim((string) ($cells[ImportableDocuments::TYPE_COLUMN] ?? '')));

            /*
             * A comment line — the template's own hint rows and its
             * commented-out worked example, which the operator activates by
             * deleting one character — skipped in silence.
             *
             * Anchored to the TYPE column and never to the physically first
             * cell. Columns are found by NAME, so tipe is first in the shipped
             * templates but fourth in an estimator's own sheet that opens with
             * uraian; testing $row[0] there tested the DESCRIPTION. A tipe=item
             * row worth Rp 999.000.000 described as "#3 Pekerjaan beton" was
             * dropped with no error and no warning while its neighbours
             * imported, which is precisely the silent-skip disaster
             * SKIP_ALIASES keeps its vocabulary narrow to prevent.
             */
            if (str_starts_with($word, '#')) {
                continue;
            }

            /*
             * TWO bounds, because they answer different questions.
             *
             * The record cap counts rows that ALLOCATE — a document, a line, or
             * an error carrying its whole cell map. It must be counted before
             * typing, or a row whose `tipe` nobody recognises is pushed and
             * returned above the check: 20.000 rows of tipe=xx once sailed past
             * it and the preview answered with a 4,4 MB body.
             *
             * But `abaikan` rows allocate NOTHING, and a real RAB is full of
             * them — one SUB TOTAL per section plus a rekapitulasi block. A
             * 4.200-item bill with 900 subtotal rows is an ordinary file, and
             * counting its subtotals against the record budget refused work
             * this importer exists to accept. The physical ceiling in
             * SpreadsheetReader still bounds the memory those rows cost.
             */
            $isSkip = $word === ImportableDocuments::SKIP
                || in_array($word, ImportableDocuments::SKIP_ALIASES, true);

            if (! $isSkip) {
                $read++;

                if ($read > SpreadsheetReader::MAX_ROWS) {
                    throw new LogicException($this->reader->rowCapMessage());
                }
            }

            // The group value is an identifier, never a number, and a workbook
            // hands back a numeric-looking code as a float — the same reason
            // castText exists for a 16-digit NIK.
            $group = ($cells[$definition['group']] ?? null) === null
                ? null
                : $this->reader->cast('text', $cells[$definition['group']]);

            if ($isSkip) {
                continue;
            }

            $type = $types[$word] ?? null;

            if ($type === null) {
                // Never skipped. A row with an amount in it and no recognisable
                // type is how a BOQ imports 8% short and nobody notices for a
                // year, so it refuses — its document if we can tell which, the
                // whole file if we cannot.
                $message = "baris {$line}: kolom ".ImportableDocuments::TYPE_COLUMN
                    .($word === '' ? ' kosong' : " berisi \"{$word}\" yang tidak dikenali")
                    .'. Isi dengan '.implode(' / ', array_keys($definition['rows']))
                    .' / '.ImportableDocuments::SKIP.', atau hapus barisnya.';

                if ($group === null && $currentGroup === null) {
                    $errors[] = $message;

                    continue;
                }

                $records[] = [
                    'line' => $line,
                    'type' => null,
                    'role' => 'line',
                    'group' => (string) ($group ?? $currentGroup),
                    'cells' => $cells,
                    'error' => $message,
                ];

                continue;
            }

            $role = $definition['rows'][$type]['role'];

            if ($group === null) {
                // A vertically merged group cell is one value in the top-left of
                // the merge and null in every row under it, so a line forward
                // fills. A header row never does: two documents must not be able
                // to merge into one because somebody forgot a cell.
                if ($role === 'header' || $currentGroup === null) {
                    $errors[] = "baris {$line}: kolom {$definition['group']} wajib diisi"
                        .($role === 'header' ? '.' : ' atau harus mengikuti baris dokumen di atasnya.');

                    continue;
                }

                $group = $currentGroup;
            }

            $currentGroup = (string) $group;

            $records[] = [
                'line' => $line,
                'type' => $type,
                'role' => $role,
                'group' => (string) $group,
                'cells' => $cells,
                'error' => null,
            ];
        }

        return [$records, $errors];
    }

    /**
     * One workbook carries many documents; the group column says which is which.
     *
     * Non-negotiable for AHSP — a price book is 200 analyses and nobody imports
     * one at a time — and it is what lets twelve branch BOQs arrive in one file.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function group(array $records): array
    {
        $grouped = [];

        foreach ($records as $record) {
            $grouped[$record['group']][] = $record;
        }

        return $grouped;
    }

    // -------------------------------------------------------------- assemble

    /**
     * One document: resolve its target, build the payload, validate, report.
     */
    private function document(array $definition, string $group, array $rows): array
    {
        $errors = [];
        $warnings = [];

        $headerRows = array_values(array_filter($rows, fn (array $row) => $row['role'] === 'header'));

        $document = [
            'group' => $group,
            'line' => $headerRows[0]['line'] ?? ($rows[0]['line'] ?? 0),
            'action' => 'create',
            'target' => null,
            'target_model' => null,
            'valid' => false,
            'errors' => [],
            'warnings' => [],
            'header' => [],
            'rows' => [],
            'totals' => ['lines' => 0, 'computed_total' => 0.0, 'stated_total' => null, 'unpriced_lines' => 0],
            // Null while the document is a create, or while it was refused
            // before its target could be resolved: there is nothing to replace.
            'replaces' => null,
            'payload' => [],
        ];

        if ($headerRows === []) {
            $document['errors'][] = "tidak ada baris bertipe {$this->headerType($definition)} untuk \"{$group}\".";

            return $document;
        }

        if (count($headerRows) > 1) {
            $lines = implode(', ', array_column($headerRows, 'line'));
            $document['errors'][] = "\"{$group}\" memiliki lebih dari satu baris kepala dokumen (baris {$lines}).";

            return $document;
        }

        [$target, $targetError] = $this->resolveTarget($definition, $group);

        if ($targetError !== null) {
            $document['errors'][] = $targetError;

            return $document;
        }

        $document['action'] = $target === null ? 'create' : 'update';
        $document['target'] = $target?->{$definition['code_column']};
        $document['target_model'] = $target;

        if ($target !== null) {
            $errors = array_merge($errors, $this->blockers($definition, $target));
            $warnings = array_merge($warnings, $this->targetWarnings($target));
        }

        // The header first: its resolved values are the context a line lookup may
        // need to scope itself (a RAP line's item_boq only means anything inside
        // that RAP's own BOQ).
        $creating = $target === null;

        $header = $this->resolveRow($definition, $definition['rows'][$headerRows[0]['type']], $headerRows[0]['cells'], [], $creating);
        $payload = $header['values'];
        $paths = ['' => $headerRows[0]['line']];

        $document['header'] = $header['display'];

        if ($header['errors'] !== []) {
            $errors = array_merge($errors, array_map(
                fn (string $message) => "baris {$headerRows[0]['line']}: {$message}",
                $header['errors'],
            ));
        }

        $presented = [];
        $containers = [];
        $computed = 0.0;
        // Lines whose price only exists after commit — see the accrual below.
        $unpriced = 0;
        $stated = null;
        $lineCount = 0;
        $leafCount = 0;

        foreach ($rows as $record) {
            if ($record['role'] === 'header') {
                continue;
            }

            $lineCount++;

            // A LEAF row is a line of the document; a bagian is a heading over
            // one. Counting the heading as a detail row let a file carrying
            // section titles and no work items satisfy the only substance check
            // there was, delete every item of a live BOQ and zero its total —
            // reported as a plain success. An untyped row keeps role 'line'
            // because it may well have been one, and it refuses the document
            // anyway.
            if ($record['role'] === 'line') {
                $leafCount++;
            }

            if ($record['type'] === null) {
                $presented[] = $this->presentRow($record, null, [$record['error']], [], []);

                continue;
            }

            $rowType = $definition['rows'][$record['type']];
            // Always as a CREATE, even when the document itself is an update:
            // every definition replaces its lines wholesale, so a line row is a
            // new record with nothing behind it to preserve. "Blank leaves the
            // stored value alone" is a statement about the header, where there
            // IS a stored value.
            $resolved = $this->resolveRow($definition, $rowType, $record['cells'], $payload);
            $rowErrors = $resolved['errors'];
            $rowWarnings = $resolved['warnings'];

            $path = null;

            if ($rowType['role'] === 'group') {
                $index = count(data_get($payload, $rowType['relation'], []));
                $path = "{$rowType['relation']}.{$index}";
                $containers[$record['type']] = ['path' => $path, 'line' => $record['line'], 'children' => 0];
            } elseif ($rowType['parent'] !== null) {
                $parent = $containers[$rowType['parent']] ?? null;

                if ($parent === null) {
                    $rowErrors[] = "{$rowType['label']} muncul sebelum baris {$rowType['parent']} mana pun.";
                } else {
                    $index = count(data_get($payload, "{$parent['path']}.{$rowType['relation']}", []));
                    $path = "{$parent['path']}.{$rowType['relation']}.{$index}";
                    $containers[$rowType['parent']]['children']++;
                }
            } else {
                $index = count(data_get($payload, $rowType['relation'], []));
                $path = "{$rowType['relation']}.{$index}";
            }

            if ($path !== null) {
                data_set($payload, $path, $resolved['values']);
                $paths[$path] = $record['line'];
            }

            if ($resolved['amount'] !== null) {
                $computed += $resolved['amount'];
            } elseif ($rowType['role'] === 'line') {
                /*
                 * A line the sheet does not price — a BOQ item costed from an
                 * AHSP analysis on commit. Its amount is unknowable HERE, and
                 * adding nothing while still printing a total turns the unknown
                 * into a confident Rp 0 under a bill worth hundreds of millions.
                 */
                $unpriced++;
            }

            if ($resolved['stated'] !== null) {
                $stated = ($stated ?? 0.0) + $resolved['stated'];
            }

            $presented[] = $this->presentRow($record, $path, $rowErrors, $rowWarnings, $resolved['display']);
        }

        foreach ($containers as $type => $container) {
            if ($container['children'] === 0) {
                $warnings[] = "baris {$container['line']}: {$definition['rows'][$type]['label']} tanpa satu pun baris di bawahnya.";
            }
        }

        if ($leafCount === 0 && $this->hasLineRows($definition)) {
            $errors[] = $lineCount === 0
                ? 'tidak ada satu pun baris rincian.'
                : 'hanya berisi baris judul bagian, tanpa satu pun baris rincian di bawahnya.';
        }

        // What this file would REPLACE, said out loud before it is committed.
        //
        // An update replaces the document's lines wholesale and nothing compared
        // the incoming sheet against the document it overwrites, so an estimator
        // who filtered the export in Excel and copied the visible rows uploaded
        // one section and one item over three sections and 400 items, and the
        // preview answered action=update, valid=true, errors=[], warnings=[].
        // There is no undo. A destructive import has to be visible while it is
        // still a preview.
        if ($target !== null) {
            $stored = $this->storedLines($definition, $target);
            $document['replaces'] = $stored + ['incoming_lines' => $leafCount, 'deleted' => max(0, $stored['lines'] - $leafCount)];

            if ($stored['lines'] > 0 && $leafCount === 0) {
                // Refused, not warned. A file with no line rows at all can only
                // empty the document, and "I lost them in Excel" is very much
                // commoner than "I meant to delete all 400". Emptying a document
                // on purpose belongs on its own screen, where the lines being
                // deleted are in front of the person deleting them.
                $errors[] = sprintf(
                    '%s berisi %d baris senilai %s dan berkas ini tidak memuat satu pun baris rincian,'
                    .' sehingga seluruh isinya akan terhapus. Kosongkan dokumen dari layarnya sendiri bila memang itu yang dimaksud.',
                    $document['target'],
                    $stored['lines'],
                    $this->rupiah($stored['total']),
                );
            } elseif ($document['replaces']['deleted'] > 0) {
                $warnings[] = sprintf(
                    'menimpa %s yang kini berisi %d baris senilai %s; berkas ini berisi %d baris — %d baris akan DIHAPUS.',
                    $document['target'],
                    $stored['lines'],
                    $this->rupiah($stored['total']),
                    $leafCount,
                    $document['replaces']['deleted'],
                );
            }
        }

        // The module's own request is the authority on what a valid document is,
        // so its rules() run against the payload we built and its messages are
        // mapped back onto the sheet rows that produced them.
        $presented = $this->validate($definition, $target, $payload, $paths, $presented, $errors);

        // Arithmetic no column pair can express — an AHSP's own stated unit price
        // against the sum of its components, say. It sees the assembled payload
        // and the document it would overwrite, and nothing else.
        if ($definition['checks'] !== null) {
            $extra = ($definition['checks'])($payload, $target);
            $errors = array_merge($errors, $extra['errors'] ?? []);
            $warnings = array_merge($warnings, $extra['warnings'] ?? []);
        }

        $refused = count(array_filter($presented, fn (array $row) => ! $row['valid']));

        $document['rows'] = $presented;
        $document['payload'] = $payload;
        $document['errors'] = array_values(array_unique(array_merge($document['errors'], $errors)));
        $document['warnings'] = array_values(array_unique($warnings));
        $document['valid'] = $document['errors'] === [] && $refused === 0;
        $document['totals'] = [
            'lines' => $lineCount,
            'refused' => $refused,
            /*
             * The sum of the lines the SHEET prices — not the document total.
             * A BOQ item costed from an AHSP analysis is priced on commit, so
             * it contributes nothing here; unpriced_lines carries how many, and
             * the screen must label the figure as partial. Printing this as
             * "the total" under a bill whose other half comes from analyses is
             * what made one card show two contradicting numbers.
             */
            'computed_total' => round($computed, 2),
            'unpriced_lines' => $unpriced,
            'stated_total' => $stated === null ? null : round($stated, 2),
        ];

        return $document;
    }

    /**
     * One row: cast every cell, resolve every code, and check the sheet's own
     * arithmetic against ours.
     *
     * $creating is load-bearing, not decoration: on a CREATE there is nothing to
     * lose, so a blank cell may take a default or be written as null; on an
     * UPDATE every value already exists, and a cell the operator left alone must
     * leave it alone.
     *
     * @return array{values: array, display: array, errors: array, warnings: array, amount: ?float, stated: ?float}
     */
    private function resolveRow(array $definition, array $rowType, array $cells, array $context = [], bool $creating = true): array
    {
        $values = [];
        $display = [];
        $errors = [];
        $warnings = [];
        $rules = [];
        $stated = null;

        foreach ($rowType['columns'] as $column) {
            $header = $column['header'];

            // A column the FILE does not carry is left out of the payload
            // entirely, and this test comes FIRST — before any default. $cells is
            // built from the file's own header row, so array_key_exists is
            // exactly "this sheet has that column".
            //
            // The default used to be applied above this line, which made the
            // guard dead for every defaulted column: a hand-made sheet whose
            // header row simply had no `diskon` and no `ppn_persen` reset a
            // negotiated 50.000.000 discount to 0 and a deliberate 0% export
            // rate back to the house 11%, on a live customer quotation, and
            // reported it as "1 diperbarui" with no error and no warning.
            if (! array_key_exists($header, $cells)) {
                continue;
            }

            $value = $cells[$header];

            // A blank cell in a column the sheet DOES carry falls back to the
            // default only while CREATING: that is the NOT NULL insert the
            // defaults exist for, and "diskon kosong = 0" is what the template
            // promises for a new penawaran. On an update the stored value
            // stands — an operator who retypes three rows and leaves the header
            // cells blank is saying "I am not changing these", not "give me the
            // house rate".
            if ($value === null && $creating && array_key_exists('default', $column)) {
                $value = $column['default'];
            }

            $written = $value;

            if ($value !== null && isset($column['cast'])) {
                try {
                    $value = $this->reader->cast($column['cast'], $value);
                } catch (InvalidArgumentException $e) {
                    $errors[] = "{$header}: {$e->getMessage()}";
                    $value = null;
                }
            }

            if ($value !== null && isset($column['enum'])) {
                $canonical = $this->canonicalise($column['enum'], (string) $value);

                if ($canonical === null) {
                    $errors[] = "{$header}: \"{$written}\" tidak dikenali. Gunakan salah satu dari "
                        .implode(', ', array_keys($column['enum'])).'.';
                }

                $value = $canonical;
            }

            if ($value !== null && isset($column['lookup'])) {
                [$value, $lookupError] = $this->lookup($column, (string) $value, $context);

                if ($lookupError !== null) {
                    $errors[] = $lookupError;
                }
            }

            if (($column['required'] ?? false) && ($value === null || $value === '')) {
                $errors[] = "{$header}: wajib diisi.";
            }

            if ($column['checksum'] ?? false) {
                $stated = $value === null ? null : (float) $value;

                continue;
            }

            if (! isset($column['field'])) {
                continue;
            }

            // A cell that is PRESENT but blank leaves the stored value alone on
            // an update, unless the registry marks the column clearable.
            //
            // The template's own documented update route is "download the
            // template — which carries every column — put the existing number in
            // `dokumen`, retype the rows you are changing". Writing null for
            // every cell the operator did not retype detached the BOQ from its
            // project, its contract and its penawaran and wiped its notes, after
            // which every RAP, baseline, WBS and variance report that finds that
            // BOQ by project stops finding it — and no screen says anything.
            // A blank cell is the ABSENCE of an instruction; deleting a link is
            // an instruction, and has to be written down as one.
            if ($value === null && ! $creating && ($column['blank'] ?? 'keep') !== 'clear') {
                continue;
            }

            $values[$column['field']] = $value;
            // The preview shows CODES, not ids: an operator can check "PRJ-2026-001"
            // against the sheet in front of them and can check nothing at all
            // against a project_id of 7.
            $display[$header] = isset($column['lookup']) ? $written : $value;

            if (($column['rules'] ?? []) !== [] && ! isset($column['lookup'])) {
                $rules[$column['field']] = array_merge(['nullable'], $column['rules']);
            }
        }

        // Per-column rules exist for the lines no FormRequest describes:
        // CostBudgetStoreRequest covers the RAP header and says nothing at all
        // about its items.
        if ($rules !== []) {
            $names = [];

            foreach ($rowType['columns'] as $column) {
                if (isset($column['field'])) {
                    $names[$column['field']] = $column['header'];
                }
            }

            foreach (Validator::make($values, $rules, [], $names)->errors()->all() as $message) {
                $errors[] = $message;
            }
        }

        $amount = null;

        if ($rowType['amount'] !== null) {
            [$qtyField, $priceField] = $rowType['amount'];

            /*
             * A MISSING price is not a zero price. A BOQ item costed from an
             * AHSP analysis carries no harga_satuan at all — the analysis
             * supplies it on commit — and (float) null silently made that line
             * worth Rp 0, which the preview then summed into a confident total
             * for a bill worth hundreds of millions. Unknown stays unknown.
             */
            $amount = ($values[$priceField] ?? null) === null || ($values[$qtyField] ?? null) === null
                ? null
                : round((float) $values[$qtyField] * (float) $values[$priceField], 2);
        }

        /*
         * The sheet states a jumlah for a line whose own arithmetic we cannot
         * do: one of the pair is empty, so there are two numbers from two
         * places and the importer must not pick a winner. Refusing says so;
         * ignoring the column would import at a price nobody in the room agreed
         * to while the sheet's printed total still looked right.
         *
         * The message names the cells that are ACTUALLY empty. It used to say
         * "berharga dari analisa (harga satuan kosong)" for every resource,
         * which is the BOQ case and only the BOQ case — a penawaran line with a
         * forgotten volume was told it was priced from an analysis, in a module
         * that has no analyses, and the operator looked at the wrong column.
         */
        if ($amount === null && $rowType['amount'] !== null && $stated !== null) {
            $blank = [];

            foreach ($rowType['amount'] as $field) {
                if (($values[$field] ?? null) === null) {
                    $blank[] = $this->headerOf($rowType, $field);
                }
            }

            $named = implode(' dan ', $blank);

            $errors[] = "jumlah: berkas menulis jumlah {$this->rupiah($stated)}, tetapi {$named} kosong"
                .' sehingga angka itu tidak dapat diperiksa. '
                ."Isi {$named}. Mengosongkan kolom jumlah hanya menyelesaikannya bila kolom yang kosong"
                .' itu memang boleh kosong — baris BOQ ber-ahsp_kode dihargai saat impor, jadi baris itu'
                .' tidak boleh menulis jumlah sama sekali; di tempat lain volume dan harga satuan tetap'
                .' wajib diisi.';
        }

        if ($amount !== null && $rowType['amount'] !== null) {

            if ($stated !== null) {
                // The estimator's own JUMLAH column is the checksum against our
                // parser. It is the only thing that catches the one ambiguity the
                // separator rules genuinely cannot resolve alone, so a drift
                // bigger than rounding refuses the line rather than warning.
                $drift = abs($amount - $stated);
                $tolerance = max(self::CHECKSUM_ABSOLUTE, abs($stated) * self::CHECKSUM_RATIO);

                if ($drift > $tolerance) {
                    $errors[] = sprintf(
                        'jumlah: berkas menulis %s, tetapi volume x harga satuan = %s. Periksa pemisah ribuan/desimal.',
                        $this->rupiah($stated),
                        $this->rupiah($amount),
                    );
                } elseif ($drift > 0.0) {
                    $warnings[] = sprintf('jumlah berbeda %s dari volume x harga satuan (pembulatan).', $this->rupiah($drift));
                }
            }

            if (($values[$qtyField] ?? null) !== null && (float) $values[$qtyField] == 0.0) {
                $warnings[] = 'volume 0 — baris ini tidak menambah nilai apa pun.';
            }
        }

        return [
            'values' => $values,
            'display' => $display,
            'errors' => $errors,
            'warnings' => $warnings,
            'amount' => $amount,
            'stated' => $stated,
        ];
    }

    /**
     * Resolve a business code to an id, refusing what it cannot find.
     *
     * Never silently nulled: a BOQ attached to no project is a different
     * document, not the same one with a field missing.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function lookup(array $column, string $code, array $context): array
    {
        [$table, $lookupColumn] = $column['lookup'];

        $query = DB::table($table)->where($lookupColumn, $code);

        if (isset($column['scope'])) {
            [$scopeColumn, $scopeKey] = $column['scope'];
            $scopeValue = $context[$scopeKey] ?? null;

            if ($scopeValue === null) {
                return [null, "{$column['header']}: tidak dapat dicari karena dokumen ini belum menunjuk induknya."];
            }

            $query->where($scopeColumn, $scopeValue);
        }

        // A soft-deleted AHSP or BOQ item is gone as far as an estimator is
        // concerned; resolving a code to one would attach a live document to a
        // record no screen can reach.
        if ($this->hasSoftDeletes($table)) {
            $query->whereNull('deleted_at');
        }

        $ids = $query->pluck('id')->all();

        if ($ids === []) {
            return [null, "{$column['header']}: \"{$code}\" tidak ditemukan."];
        }

        if (count($ids) > 1) {
            // wbs_code is not unique inside a BOQ at the database level. Binding
            // to the first match would put a whole cost line against the wrong
            // work item and nothing downstream would ever say so.
            return [null, "{$column['header']}: \"{$code}\" cocok dengan ".count($ids).' baris; buat kodenya unik dulu.'];
        }

        return [(int) $ids[0], null];
    }

    /**
     * The module's own FormRequest decides what a valid document is; this maps
     * its messages back onto the rows of the sheet that produced them.
     */
    private function validate(array $definition, ?object $target, array $payload, array $paths, array $presented, array &$errors): array
    {
        if ($definition['request'] === null) {
            return $presented;
        }

        $rules = (new $definition['request'])->rules();

        if ($target !== null && $definition['update_rules'] !== null) {
            $rules = ($definition['update_rules'])($rules, $target);
        }

        $names = $this->attributeNames($definition);
        $validator = Validator::make($payload, $rules, [], $names);

        $byPath = [];

        foreach ($presented as $index => $row) {
            if ($row['path'] !== null) {
                $byPath[$row['path']] = $index;
            }
        }

        foreach ($validator->errors()->messages() as $key => $messages) {
            $path = $this->pathOf((string) $key, $paths);
            $header = $names[$this->wildcard((string) $key)] ?? null;
            $index = $path === null ? null : ($byPath[$path] ?? null);

            foreach ($messages as $message) {
                // The engine already named this cell in its own words; saying it
                // twice in two dialects helps nobody.
                if ($index !== null && $header !== null && $this->alreadyReported($presented[$index]['errors'], $header)) {
                    continue;
                }

                if ($index === null) {
                    $line = $path === null ? null : ($paths[$path] ?? null);
                    $errors[] = $line === null ? $message : "baris {$line}: {$message}";

                    continue;
                }

                $presented[$index]['errors'][] = $message;
                $presented[$index]['valid'] = false;
            }
        }

        return $presented;
    }

    // ------------------------------------------------------------- the target

    /**
     * @return array{0: ?object, 1: ?string}
     */
    private function resolveTarget(array $definition, string $group): array
    {
        $model = $definition['model'];
        $target = $model::query()->where($definition['code_column'], $group)->first();

        if ($target !== null) {
            return [$target, null];
        }

        $prefix = $this->numberPrefix($definition);

        // A value that LOOKS like this type's document number but exists nowhere
        // is a typo in a code somebody meant to update. Left alone it would mint
        // a second document with a fresh number, and nobody would notice until
        // two BOQs disagreed.
        if ($prefix !== null && str_starts_with(strtoupper($group), strtoupper($prefix))) {
            return [null, "{$group} tidak ditemukan; kosongkan atau ganti kolom {$definition['group']}"
                .' dengan label bebas untuk membuat dokumen baru, atau perbaiki nomornya.'];
        }

        return [null, null];
    }

    /**
     * The literal head of the configured document number, e.g. "BOQ/".
     *
     * Read from config/erp.php rather than repeated in the registry, so a site
     * that renumbers its BOQs does not silently lose this guard.
     */
    private function numberPrefix(array $definition): ?string
    {
        if ($definition['document_type'] === null) {
            return null;
        }

        $format = Erp::string("documents.{$definition['document_type']}", '');
        $head = strtok($format, '{');

        return $head === false || $head === '' ? null : $head;
    }

    /**
     * @return array<int, string>
     */
    private function blockers(array $definition, object $target): array
    {
        $errors = [];
        $status = $target->status ?? null;

        // An import never overwrites an approved document and never creates one
        // already approved: no template carries a status column, so no file can
        // ask for one, and a target past draft refuses its whole group.
        if ($status instanceof DocumentStatus && ! $status->isEditable()) {
            $errors[] = "{$target->{$definition['code_column']}} berstatus {$status->label()}"
                .' dan tidak dapat diperbarui; buat Versi Baru lalu impor ke versi itu.';
        }

        if ($definition['blockers'] !== null) {
            $errors = array_merge($errors, ($definition['blockers'])($target));
        }

        return $errors;
    }

    /**
     * How much of this document already exists: its stored LEAF lines and what
     * they are worth, counted exactly the way the file's own lines are counted.
     *
     * Walked from the definition rather than read off a `total` column, so it
     * works for a flat penawaran, a sectioned BOQ and an AHSP alike, and cannot
     * disagree with the number the preview computes for the incoming sheet.
     *
     * @return array{lines: int, total: float}
     */
    private function storedLines(array $definition, object $target): array
    {
        $lines = 0;
        $total = 0.0;

        $add = function (iterable $records, array $rowType) use (&$lines, &$total): void {
            foreach ($records as $record) {
                $lines++;

                if ($rowType['amount'] === null) {
                    continue;
                }

                [$qtyField, $priceField] = $rowType['amount'];
                $total += (float) ($record->{$qtyField} ?? 0) * (float) ($record->{$priceField} ?? 0);
            }
        };

        foreach ($definition['rows'] as $rowType) {
            if ($rowType['role'] !== 'line') {
                continue;
            }

            if ($rowType['parent'] === null) {
                $add($target->{$rowType['relation']}, $rowType);

                continue;
            }

            foreach ($target->{$definition['rows'][$rowType['parent']]['relation']} as $group) {
                $add($group->{$rowType['relation']}, $rowType);
            }
        }

        return ['lines' => $lines, 'total' => round($total, 2)];
    }

    /**
     * Whether this document type has leaf lines at all, so the "no rincian"
     * refusal never fires against a definition that never had any.
     */
    private function hasLineRows(array $definition): bool
    {
        foreach ($definition['rows'] as $row) {
            if ($row['role'] === 'line') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function targetWarnings(object $target): array
    {
        $status = $target->status ?? null;

        return $status instanceof DocumentStatus && $status === DocumentStatus::Rejected
            ? ['dokumen ini pernah diajukan dan ditolak; impor akan menimpa isinya.']
            : [];
    }

    // ----------------------------------------------------------------- export

    /**
     * @return iterable<object>
     */
    private function exportable(array $definition, array $filters): iterable
    {
        $model = $definition['model'];
        $query = $model::query();

        if (($filters['kode'] ?? null) !== null && $filters['kode'] !== '') {
            $query->where($definition['code_column'], $filters['kode']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            // Validated against the resource, because not every document has a
            // status. est_ahsp has no such column: on SQLite the unresolvable
            // identifier degrades to a string literal, the predicate is simply
            // false, and the operator downloads a file containing the header row
            // and nothing else — indistinguishable from an empty price book, on
            // the endpoint that IS the recovery path after a create-import. On
            // MySQL or Postgres the same request is an unhandled 500.
            if (! $this->hasColumn((new $model)->getTable(), 'status')) {
                throw new LogicException(
                    "{$definition['label']} tidak memiliki kolom status, sehingga tidak dapat disaring menurut status."
                    .' Hapus parameter status dari permintaan ekspor.',
                );
            }

            $query->where('status', $filters['status']);
        }

        return $query->orderBy($definition['code_column'])->cursor();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function exportRows(array $definition, object $document): array
    {
        $code = (string) $document->{$definition['code_column']};
        $rows = [];

        foreach ($definition['rows'] as $type => $rowType) {
            if ($rowType['role'] === 'header') {
                $rows[] = $this->exportRow($definition, $rowType, $type, $code, $document);
            }
        }

        foreach ($definition['rows'] as $type => $rowType) {
            if ($rowType['role'] === 'group') {
                foreach ($document->{$rowType['relation']} as $section) {
                    $rows[] = $this->exportRow($definition, $rowType, $type, $code, $section);

                    foreach ($definition['rows'] as $childType => $childRow) {
                        if (($childRow['parent'] ?? null) === $type) {
                            foreach ($section->{$childRow['relation']} as $line) {
                                $rows[] = $this->exportRow($definition, $childRow, $childType, $code, $line);
                            }
                        }
                    }
                }
            }
        }

        foreach ($definition['rows'] as $type => $rowType) {
            if ($rowType['role'] === 'line' && $rowType['parent'] === null) {
                foreach ($document->{$rowType['relation']} as $line) {
                    $rows[] = $this->exportRow($definition, $rowType, $type, $code, $line);
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function exportRow(array $definition, array $rowType, string $type, string $code, object $record): array
    {
        $row = [
            ImportableDocuments::TYPE_COLUMN => $type,
            $definition['group'] => $code,
        ];

        foreach ($rowType['columns'] as $column) {
            if ($column['checksum'] ?? false) {
                if ($rowType['amount'] !== null) {
                    [$qtyField, $priceField] = $rowType['amount'];
                    $row[$column['header']] = $this->exportNumber(
                        (float) ($record->{$qtyField} ?? 0) * (float) ($record->{$priceField} ?? 0),
                        2,
                    );
                }

                continue;
            }

            if (! isset($column['field'])) {
                continue;
            }

            $row[$column['header']] = $this->exportValue($record, $column);
        }

        return $row;
    }

    private function exportValue(object $record, array $column): string
    {
        if (isset($column['lookup'])) {
            [$table, $lookupColumn] = $column['lookup'];
            $id = $record->{$column['field']} ?? null;

            return $id === null ? '' : (string) DB::table($table)->where('id', $id)->value($lookupColumn);
        }

        $value = $record->{$column['field']} ?? null;

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'ya' : 'tidak';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (isset(self::EXPORT_SCALES[$column['cast'] ?? ''])) {
            return $this->exportNumber($value, self::EXPORT_SCALES[$column['cast']]);
        }

        return (string) (is_object($value) && enum_exists($value::class) ? $value->value : $value);
    }

    /**
     * A number written so that THIS importer reads it back as the same number.
     *
     * Eloquent's `decimal:3` cast hands a qty of two back as the string "2.000",
     * and castAmount reads a strict 1-3/3/3 dot group in a volume column as
     * thousands — so an exported BOQ, re-imported unchanged, asked for 2.000 m3
     * of concrete where the estimator wrote 2. The checksum catches it and
     * refuses the document, which is the right failure but it means the export
     * round trip — the bulk-edit workflow this endpoint exists for — never
     * worked at all. A qty of 150,5 was worse: "150.500" IS a valid thousands
     * group, so trimming zeroes alone would not have saved it.
     *
     * Writing the decimal mark as a comma and never grouping removes the
     * ambiguity outright, and it is how an Indonesian sheet writes a number
     * anyway. csvCell quotes the cell, so the comma cannot split a column.
     */
    private function exportNumber(mixed $value, int $scale): string
    {
        $text = number_format((float) $value, $scale, ',', '');

        // 2,000 -> 2 and 1,500 -> 1,5: trailing zeroes carry no information, and
        // a whole number should read as one.
        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    // ------------------------------------------------------------------ util

    /**
     * Every heading the file may carry, `tipe` and the group column first.
     *
     * @return array<int, string>
     */
    private function headers(array $definition): array
    {
        $headers = [ImportableDocuments::TYPE_COLUMN, $definition['group']];

        foreach ($definition['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                if (! in_array($column['header'], $headers, true)) {
                    $headers[] = $column['header'];
                }
            }
        }

        return $headers;
    }

    /**
     * A required column missing from the FILE is one message naming it, not N
     * identical row errors.
     */
    private function assertRequiredColumns(array $definition, array $positions): void
    {
        $missing = [];

        foreach ($definition['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                if (($column['required'] ?? false) && ! isset($positions[$column['header']]) && ! in_array($column['header'], $missing, true)) {
                    $missing[] = $column['header'];
                }
            }
        }

        if ($missing !== []) {
            throw new LogicException('Kolom wajib tidak ditemukan di berkas: '.implode(', ', $missing).'.');
        }
    }

    private function hasChecksumColumn(array $definition, array $positions): bool
    {
        foreach ($definition['rows'] as $row) {
            foreach ($row['columns'] as $column) {
                if (($column['checksum'] ?? false) && isset($positions[$column['header']])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The word that may appear in `tipe` => the row type it names.
     *
     * @return array<string, string>
     */
    private function typeWords(array $definition): array
    {
        $words = [];

        foreach ($definition['rows'] as $type => $row) {
            $words[strtolower($type)] = $type;

            foreach ($row['aliases'] as $alias) {
                $words[strtolower($alias)] = $type;
            }
        }

        return $words;
    }

    private function headerType(array $definition): string
    {
        foreach ($definition['rows'] as $type => $row) {
            if ($row['role'] === 'header') {
                return $type;
            }
        }

        return 'dokumen';
    }

    private function canonicalise(array $enum, string $value): ?string
    {
        $needle = strtolower(trim($value));

        foreach ($enum as $canonical => $synonyms) {
            if ($needle === strtolower((string) $canonical)) {
                return (string) $canonical;
            }

            foreach ((array) $synonyms as $synonym) {
                if ($needle === strtolower((string) $synonym)) {
                    return (string) $canonical;
                }
            }
        }

        return null;
    }

    /**
     * The heading a payload field of this row type was typed under.
     *
     * The same translation attributeNames() does for the validator, for the one
     * message that builds its own text: a refusal naming `unit_price` sends an
     * estimator hunting for a column that is nowhere in their sheet, which says
     * harga_satuan — and for an AHSP component the quantity is `koefisien`, not
     * volume, so a hardcoded word would be wrong on one resource in two.
     */
    private function headerOf(array $rowType, string $field): string
    {
        foreach ($rowType['columns'] as $column) {
            if (($column['field'] ?? null) === $field) {
                return $column['header'];
            }
        }

        return $field;
    }

    /**
     * Validator attribute names, keyed by the wildcard path the rules use, so a
     * message about sections.0.items.2.qty says "volume" — the heading the
     * operator actually typed under.
     *
     * @return array<string, string>
     */
    private function attributeNames(array $definition): array
    {
        $names = [];

        foreach ($definition['rows'] as $row) {
            $prefix = '';

            if ($row['role'] !== 'header') {
                $parent = $row['parent'] === null ? null : $definition['rows'][$row['parent']];
                $prefix = ($parent === null ? '' : "{$parent['relation']}.*.")."{$row['relation']}.*.";
            }

            foreach ($row['columns'] as $column) {
                if (isset($column['field'])) {
                    $names[$prefix.$column['field']] = $column['header'];
                }
            }
        }

        return $names;
    }

    private function wildcard(string $key): string
    {
        return (string) preg_replace('/(^|\.)\d+(?=\.|$)/', '$1*', $key);
    }

    /**
     * The payload path an error key belongs to: everything but its last segment.
     */
    private function pathOf(string $key, array $paths): ?string
    {
        $segments = explode('.', $key);
        array_pop($segments);
        $path = implode('.', $segments);

        return array_key_exists($path, $paths) ? $path : null;
    }

    private function alreadyReported(array $errors, string $header): bool
    {
        foreach ($errors as $error) {
            if (str_starts_with($error, "{$header}:")) {
                return true;
            }
        }

        return false;
    }

    private function presentRow(array $record, ?string $path, array $errors, array $warnings, array $display): array
    {
        return [
            'line' => $record['line'],
            'tipe' => $record['type'],
            'path' => $path,
            'valid' => $errors === [],
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'values' => $display,
        ];
    }

    private function summarise(array $documents, array $fileErrors): array
    {
        $valid = array_filter($documents, fn (array $document) => $document['valid'] && $fileErrors === []);

        return [
            'documents' => count($documents),
            'to_create' => count(array_filter($valid, fn (array $document) => $document['action'] === 'create')),
            'to_update' => count(array_filter($valid, fn (array $document) => $document['action'] === 'update')),
            'refused' => count($documents) - count($valid),
            'lines_read' => array_sum(array_column(array_column($documents, 'totals'), 'lines')),
            'lines_refused' => array_sum(array_column(array_column($documents, 'totals'), 'refused')),
        ];
    }

    /**
     * Maker-checker for a document a definition lands as `submitted`.
     *
     * Measured on production 4 Sep 2026 (HASIL-UJI §6 P-3, ANALISIS-PROSES §3
     * C3): a PR that reached `submitted` without submit() carried no
     * `submitted` row in core_approvals, so SegregationOfDuties saw no maker
     * and its own requester approved it. A file is that path in another coat
     * — the module's service is handed the payload and may leave the document
     * submitted, and then nobody has clicked Ajukan. The engine's own fact,
     * written by the engine, exactly like the provenance stamp above: the
     * person who uploaded the file asserted the document, so the row names
     * them and the guard refuses them one screen later. Inside the document's
     * transaction for the same reason the stamp is.
     *
     * Nothing is written for a draft (nothing was asserted), for a document
     * whose service already recorded its submission (submit() writes its own
     * row), or when there is no importer to name — an unnamed row would
     * SILENCE the guard where its owner-column fallback could still speak.
     */
    private function recordSubmission(object $record, ?User $by): void
    {
        if ($by === null || ! method_exists($record, 'approvals')) {
            return;
        }

        $status = $record->status ?? null;

        if (! ($status instanceof DocumentStatus) || $status !== DocumentStatus::Submitted) {
            return;
        }

        if ($record->approvals()->where('action', 'submitted')->exists()) {
            return;
        }

        $record->approvals()->create([
            'action' => 'submitted',
            'user_id' => $by->getKey(),
            'note' => null,
        ]);
    }

    /**
     * Memoised per instance, never statically: a per-PROCESS schema memo
     * survives the rebuild of the in-memory test database and then reports
     * columns that no longer exist.
     */
    private function hasColumn(string $table, string $column): bool
    {
        return $this->columns["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }

    private function hasSoftDeletes(string $table): bool
    {
        return $this->hasColumn($table, 'deleted_at');
    }

    private function rupiah(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
