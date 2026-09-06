<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Core\Models\SavedReport;
use Modules\Core\Support\ReportableResources;
use Modules\Core\Support\ReportDefinition;
use Modules\Core\Support\SpaEnums;
use Modules\Core\Support\XlsxSheetWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Berkas XLSX sebuah laporan bebas TERSIMPAN (Fase 1 / P1-F).
 *
 * HANYA laporan tersimpan, dan itu keputusan bukan keterbatasan: berkasnya
 * menyebut NAMA laporannya di judul dan di nama berkas, dan sebuah unduhan
 * bernama "laporan-bebas-2026-09-06.xlsx" yang isinya tidak bisa dilacak
 * kembali ke pertanyaan yang menghasilkannya adalah lampiran rapat yang tidak
 * bisa dipertanggungjawabkan. Laporan ad-hoc mengunduh CSV, yang tersedia
 * seketika di layar.
 *
 * TIGA KUERI, paling banyak: satu agregat (ReportRunner, dijamin satu) plus
 * paling banyak dua kamus id→label untuk dimensi ber-FK, masing-masing satu
 * `whereIn` atas kunci yang SUDAH dibatasi plafon 200 kelompok. Kamus tidak
 * pernah mengubah satu angka pun — ia berjalan SESUDAH agregasi — jadi janji
 * "satu kueri" milik `POST reports/run` tetap utuh dan yang di sini adalah
 * janji yang berbeda dan lebih longgar, disebutkan apa adanya.
 *
 * LABEL. Di layar dan di CSV, label ditulis peramban (`labelFor`/`enumLabel`)
 * — fungsi yang sama dengan layar daftarnya. Di sini peramban tidak ada, jadi
 * enum dibaca SpaEnums (dari enums.js yang sama) dan FK dibaca dari tabelnya
 * dengan format `"{kode} — {nama}"`, format yang sama dengan `lookup.js
 * labelFor`. SATU perbedaan yang diketahui dan tidak dipalsukan: picker vendor
 * di layar memakai `picker_label` (rating dan bendera masalah, dihitung
 * Resource); XLSX menulis `"{kode} — {nama}"`. Sebuah rating di dalam sel
 * laporan bukan yang dicari siapa pun.
 */
final class ReportXlsxExportService
{
    /**
     * Kamus FK: lookup schema.js → tabel dan kolomnya.
     *
     * Hanya tiga, karena hanya tiga yang dipakai sebagai dimensi di seluruh
     * katalog. Setiap tabel diperiksa keberadaannya sebelum dibaca (aturan
     * degradasi registri): kamus yang tabelnya hilang menulis id apa adanya,
     * bukan menjatuhkan ekspor.
     *
     * @var array<string, array{table: string, label: string, sub: string}>
     */
    private const LOOKUPS = [
        'projects' => ['table' => 'prj_projects', 'label' => 'name', 'sub' => 'code'],
        'customers' => ['table' => 'crm_customers', 'label' => 'name', 'sub' => 'code'],
        'vendors' => ['table' => 'prc_vendors', 'label' => 'name', 'sub' => 'code'],
    ];

    public function __construct(private readonly ReportRunner $runner) {}

    /**
     * @return array{filename: string, content: string}
     */
    public function export(SavedReport $report): array
    {
        // Divalidasi ULANG: katalog berubah lebih cepat daripada baris
        // tersimpan, dan kolom yang dicabut harus menolak dengan namanya.
        $definition = ReportDefinition::validate($report->definition + ['resource' => $report->resource]);

        /* Separuh KEDUA aturan degradasi registri, di jalur kedua yang
           menjalankan kueri. `ReportController::run()` memilikinya sejak
           putaran verifikasi pertama; jalur ini tidak, dan sebuah laporan
           tersimpan atas modul yang belum termigrasi menjawab 500 dengan SQL
           mentah — termasuk lintasan berkas basis data — alih-alih kalimat
           yang mengatakannya (temuan verifikasi kedua P1-F). */
        if (! ReportableResources::installed($definition['resource'])) {
            throw new InvalidArgumentException(sprintf(
                'Sumber "%s" tidak tersedia di server ini — tabelnya belum terpasang.',
                $definition['resource'],
            ));
        }

        $entry = ReportableResources::definition($definition['resource']);
        $result = $this->runner->run($definition);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan');

        $row = 1;
        XlsxSheetWriter::putHeadRow($sheet, $row, [$report->name]);
        XlsxSheetWriter::putRow($sheet, $row, ['Sumber', $entry['label']]);
        XlsxSheetWriter::putRow($sheet, $row, ['Dibuat', now()->format('d/m/Y H:i')]);

        if ($entry['date_column'] !== null) {
            XlsxSheetWriter::putRow($sheet, $row, [
                'Jendela tanggal',
                ($definition['filters']['date_from'] ?? 'awal').' s.d. '.($definition['filters']['date_to'] ?? 'kini'),
            ]);
        }

        /* Aritmetikanya, ditulis: sebuah ekspor SUM dan sebuah ekspor AVG atas
           pertanyaan yang sama menghasilkan berkas yang tidak bisa dibedakan
           tanpa baris ini (temuan verifikasi P1-F). */
        if ($definition['mode'] !== 'detail') {
            XlsxSheetWriter::putRow($sheet, $row, ['Ukuran', $this->measureLabel($entry, $definition)]);
        }

        // Baris yang mengaku apa yang TIDAK dihitung: dokumen yang dibuang.
        XlsxSheetWriter::putRow($sheet, $row, [
            'Catatan',
            $entry['soft_deletes']
                ? 'Dokumen yang sudah dihapus tidak dihitung.'
                : 'Tabel ini tidak mengenal penghapusan lunak; seluruh barisnya dihitung.',
        ]);
        $row++;

        $definition['mode'] === 'detail'
            ? $this->writeDetail($sheet, $row, $entry, $definition, $result)
            : $this->writeGrouped($sheet, $row, $entry, $definition, $result);

        $path = tempnam(sys_get_temp_dir(), 'laporan_xlsx_');
        (new Xlsx($spreadsheet))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return [
            'filename' => $this->filename($report->name),
            'content' => $content,
        ];
    }

    /* -------------------------------------------------------------- penulis */

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $result
     */
    private function writeDetail($sheet, int &$row, array $entry, array $definition, array $result): void
    {
        $columns = $definition['columns'];
        $dictionaries = $this->dictionariesFor($entry, $columns, $result['rows']);

        XlsxSheetWriter::putHeadRow($sheet, $row, array_map(
            static fn (string $key): string => $entry['columns'][$key]['label'],
            $columns,
        ));

        foreach ($result['rows'] as $line) {
            XlsxSheetWriter::putRow($sheet, $row, array_map(
                fn (string $key) => $this->render($entry['columns'][$key], $line[$key], $dictionaries),
                $columns,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $result
     */
    private function writeGrouped($sheet, int &$row, array $entry, array $definition, array $result): void
    {
        $rowColumn = $entry['columns'][$definition['row']['column']];
        $pivot = $definition['mode'] === 'pivot';
        $columnColumn = $pivot ? $entry['columns'][$definition['column']['column']] : null;

        $keys = array_column($result['rows'], 'key');
        $dictionaries = $this->dictionaryFor($rowColumn, $keys);

        if ($pivot) {
            $dictionaries += $this->dictionaryFor($columnColumn, $result['column_keys']);
        }

        $head = [$rowColumn['label']];

        foreach (($pivot ? $result['column_keys'] : [$this->measureLabel($entry, $definition)]) as $key) {
            $head[] = $pivot
                ? $this->renderKey($columnColumn, $key, $definition['column']['bucket'] ?? null, $dictionaries)
                : $key;
        }

        if ($pivot) {
            $head[] = 'Total';
        }

        XlsxSheetWriter::putHeadRow($sheet, $row, $head);

        foreach ($result['rows'] as $line) {
            $cells = [$this->renderKey($rowColumn, $line['key'], $definition['row']['bucket'] ?? null, $dictionaries)];

            /* Di SINI aturan "sel kosong, bukan 0" mendarat di kertas: nilai
               null diteruskan apa adanya ke XlsxSheetWriter, yang tidak menulis
               apa pun ke selnya. Sebuah 0.0 sungguhan ditulis 0. */
            foreach ($line['cells'] as $value) {
                $cells[] = $value;
            }

            if ($pivot) {
                $cells[] = $line['total'];
            }

            XlsxSheetWriter::putRow($sheet, $row, $cells);
        }
    }

    /**
     * "Jumlah — Nilai buku" / "Banyak baris": agregat DAN kolomnya.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $definition
     */
    private function measureLabel(array $entry, array $definition): string
    {
        $measure = $definition['measure'] ?? ['agg' => 'count'];
        $names = ['sum' => 'Jumlah', 'avg' => 'Rata-rata', 'min' => 'Terkecil', 'max' => 'Terbesar', 'count' => 'Banyak baris'];
        $agg = $names[$measure['agg']] ?? $measure['agg'];

        return isset($measure['column'])
            ? $agg.' — '.$entry['columns'][$measure['column']]['label']
            : $agg;
    }

    /* -------------------------------------------------------------- kamus */

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, string>>
     */
    private function dictionariesFor(array $entry, array $columns, array $rows): array
    {
        $out = [];

        foreach ($columns as $key) {
            $column = $entry['columns'][$key];

            if (($column['lookup'] ?? null) === null) {
                continue;
            }

            $out += $this->dictionaryFor($column, array_column($rows, $key));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  list<mixed>  $ids
     * @return array<string, array<string, string>>
     */
    private function dictionaryFor(array $column, array $ids): array
    {
        $lookup = $column['lookup'] ?? null;

        if ($lookup === null || ! isset(self::LOOKUPS[$lookup])) {
            return [];
        }

        $source = self::LOOKUPS[$lookup];

        if (! Schema::hasTable($source['table'])) {
            return [];
        }

        $wanted = array_values(array_unique(array_filter($ids, static fn ($one): bool => $one !== null && $one !== '')));

        if ($wanted === []) {
            return [$lookup => []];
        }

        $rows = DB::table($source['table'])
            ->whereIn('id', $wanted)
            ->get(['id', $source['label'], $source['sub']]);

        $map = [];
        foreach ($rows as $one) {
            $label = (string) ($one->{$source['label']} ?? '');
            $sub = (string) ($one->{$source['sub']} ?? '');
            // Format yang sama dengan lookup.js labelFor: "{sub} — {label}".
            $map[(string) $one->id] = $sub !== '' ? trim($sub.' — '.$label) : $label;
        }

        return [$lookup => $map];
    }

    /* ------------------------------------------------------------ penyaji */

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, array<string, string>>  $dictionaries
     */
    private function render(array $column, mixed $value, array $dictionaries): mixed
    {
        if ($value === null) {
            return null;
        }

        /* MySQL mengembalikan DECIMAL sebagai STRING lewat PDO sementara SQLite
           mengembalikannya sebagai float. Tanpa cast ini, setiap kolom uang
           pada ekspor rincian ditulis sebagai TEKS di MySQL — rata kiri, dan
           SUM Excel atasnya menghasilkan 0. Registri tahu jenisnya; di sinilah
           satu-satunya tempat yang tahu. */
        if (in_array($column['type'], ['currency', 'percent', 'progress', 'number'], true) && is_numeric($value)) {
            return (float) $value;
        }

        if (($column['enum'] ?? null) !== null) {
            return SpaEnums::label($column['enum'], $value);
        }

        if (($column['lookup'] ?? null) !== null) {
            // Kamus meleset (barisnya dihapus sesudah laporan dibuat) → '#12',
            // fallback yang sama dengan cells.js di layar.
            return $dictionaries[$column['lookup']][(string) $value] ?? '#'.$value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, array<string, string>>  $dictionaries
     */
    private function renderKey(array $column, mixed $key, ?string $bucket, array $dictionaries): string
    {
        // Kelompok "tanpa nilai" adalah kelompok yang sah — proyek yang belum
        // diisi tetap punya biayanya — jadi ia dinamai, bukan dibuang.
        if ($key === null) {
            return '(kosong)';
        }

        if ($bucket !== null) {
            return (string) $key;
        }

        $rendered = $this->render($column, $key, $dictionaries);

        return $rendered === null ? '(kosong)' : (string) $rendered;
    }

    private function filename(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? 'laporan');

        return trim($slug, '-').'-'.now()->format('Ymd').'.xlsx';
    }
}
