<?php

namespace Modules\Core\Services;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Ekspor XLSX untuk formulir rumah tersering (P8).
 *
 * Bukan mesin cetak kedua. Semua yang ditulis ke sheet berasal dari
 * FormPrintService::composed() — data yang PERSIS sama yang dirender blade
 * cetaknya — sehingga sebuah angka tidak mungkin berbeda antara kertas dan
 * Excel-nya: keduanya membaca satu komposisi. Yang di kertas bergaris (sel
 * tanpa sumber data) di sini adalah sel KOSONG. Tidak pernah 0, tidak pernah
 * "-": Excel yang menjumlahkan kolom berisi nol karangan menghasilkan total
 * yang kelihatan benar, dan itulah persisnya yang aturan kejujuran larang.
 *
 * SEPULUH FORMULIR, BUKAN SEMUA. Ekspor berguna untuk lembar yang orang olah
 * lanjut di Excel — rekap, saldo, opname, dokumen pengadaan — dan daftar ini
 * memilih sepuluh yang paling sering dipakai dari katalog cetak. Formulir
 * registri diekspor generik (bentuk komposisinya seragam); laporan harian,
 * satu-satunya formulir bespoke di daftar, punya pemetaannya sendiri di
 * bawah. Menambahkan formulir = menambah satu slug di FORMS (dan, bila
 * bespoke, satu pemetaan) — bukan menyalin logika komposisi.
 */
class FormXlsxExportService
{
    /**
     * The ten most-used house forms, by slug of the print catalogue.
     *
     * laporan-harian is bespoke; the other nine are registry-composed and
     * share one generic walk. FormXlsxExportTest pins that every slug here
     * still resolves in FormPrintService::definition().
     */
    public const FORMS = [
        'laporan-harian',      // F/LH  — laporan harian proyek
        'saldo-stok',          // F/SS  — daftar saldo stok per gudang
        'bon-material',        // bon pemakaian material
        'penerimaan-barang',   // penerimaan barang (GR)
        'berita-acara-opname', // F/BAO — berita acara stock opname
        'permintaan-pembelian', // PR
        'order-pembelian',     // PO
        'spk-subkon',          // SPK subkontraktor
        'opname-owner',        // F/OPN — opname progres ke pemilik
        'rekap-upah',          // F/RU  — rekap upah mandor per periode
    ];

    public function __construct(private readonly FormPrintService $forms) {}

    /**
     * @return array{filename: string, content: string}
     */
    public function export(string $form, array $context = []): array
    {
        if (! in_array($form, self::FORMS, true)) {
            // Slug yang DIKENAL katalog tetapi di luar daftar ini: penolakan
            // menyebut nama dan menunjuk jalur yang ada, bukan pura-pura 404.
            throw new InvalidArgumentException(
                "Formulir {$form} belum tersedia sebagai XLSX; cetak HTML-nya tetap ada. "
                .'Daftar formulir ber-XLSX: '.implode(', ', self::FORMS).'.'
            );
        }

        $document = $this->forms->composed($form, $context);
        $data = $document['data'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Formulir');

        $row = 1;

        $this->writeHead($sheet, $row, $data);

        if ($form === 'laporan-harian') {
            $this->writeLaporanHarian($sheet, $row, $data);
        } else {
            $this->writeRegistryTables($sheet, $row, $data['tables'] ?? []);
        }

        $this->writeNotes($sheet, $row, $data['notes'] ?? null);

        $path = tempnam(sys_get_temp_dir(), 'form_xlsx_');
        (new Xlsx($spreadsheet))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return [
            'filename' => $form.'-'.($context['id'] ?? 0).'.xlsx',
            'content' => $content,
        ];
    }

    // ------------------------------------------------------------- the head

    /**
     * Judul formulir, kop empat pihak yang diringkas, dan blok identitas —
     * baris label|nilai, nilai null tetap kosong.
     */
    private function writeHead(Worksheet $sheet, int &$row, array $data): void
    {
        $this->line($sheet, $row, [$data['formTitle'] ?? null, $data['formCode'] ?? null]);

        $header = $data['header'] ?? [];

        foreach ($header['parties'] ?? [] as $party) {
            if (($party['caption'] ?? null) === null) {
                continue;
            }

            $this->line($sheet, $row, [$party['caption'], $party['name'] ?? null, $party['meta'] ?? null]);
        }

        if (($header['pekerjaan'] ?? null) !== null) {
            $this->line($sheet, $row, ['PEKERJAAN', $header['pekerjaan']]);
        }

        foreach ($header['identity'] ?? [] as $line) {
            $this->line($sheet, $row, [$line['label'], $line['value']]);
        }

        $row++; // satu baris kosong sebelum tabel tubuh
    }

    // -------------------------------------------------- registry (9 slugs)

    /**
     * Tabel-tabel komposisi registri, apa adanya: selnya SUDAH string yang
     * dibuktikan komposer, atau null — dan null tetap kosong. `blanks` ikut
     * ditulis sebagai baris kosong: itu pad yang diisi tangan di kertas, dan
     * di Excel pun ia milik pena, bukan milik angka karangan.
     */
    private function writeRegistryTables(Worksheet $sheet, int &$row, array $tables): void
    {
        foreach ($tables as $table) {
            if (($table['title'] ?? null) !== null) {
                $this->line($sheet, $row, [$table['title']]);
            }

            $this->line($sheet, $row, array_map(
                fn (array $column): string => (string) $column['label'],
                $table['columns'],
            ));

            foreach ($table['rows'] as $cells) {
                $this->line($sheet, $row, $cells);
            }

            for ($i = 0; $i < (int) ($table['blanks'] ?? 0); $i++) {
                $row++;
            }

            foreach ($table['totals'] ?? [] as $total) {
                $this->line($sheet, $row, [$total['label'], $total['value']]);
            }

            $row++;
        }
    }

    // ------------------------------------------------ laporan harian (F/LH)

    /**
     * Pemetaan bespoke satu-satunya: tubuh laporan harian, tabel demi tabel,
     * dari array yang sama yang dirender laporan-harian.blade.php. Jabatan
     * tanpa entri hari itu (count null) adalah sel kosong — pad FM-10-12
     * menggarisnya, Excel mengosongkannya.
     */
    private function writeLaporanHarian(Worksheet $sheet, int &$row, array $data): void
    {
        $this->line($sheet, $row, ['CUACA PAGI', $data['weather']['pagi'] ?? null]);
        $this->line($sheet, $row, ['CUACA SORE', $data['weather']['sore'] ?? null]);
        $this->line($sheet, $row, ['JAM KERJA', $data['workHours']['start'] ?? null, $data['workHours']['end'] ?? null]);
        $this->line($sheet, $row, ['KEGIATAN', $data['activities'] ?? null]);
        $this->line($sheet, $row, ['KENDALA', $data['obstacles'] ?? null]);
        $this->line($sheet, $row, ['CATATAN K3', $data['safetyNotes'] ?? null]);
        $row++;

        $this->line($sheet, $row, ['TENAGA KERJA']);
        $this->line($sheet, $row, ['JABATAN', 'JUMLAH ORANG']);

        foreach ($data['manpower'] ?? [] as $line) {
            $this->line($sheet, $row, [$line['label'], $line['count']]);
        }

        $row++;

        $this->line($sheet, $row, ['MATERIAL YANG MASUK HARI INI']);
        $this->line($sheet, $row, ['URAIAN', 'DITERIMA', 'DITOLAK', 'SATUAN', 'ALASAN TOLAK']);

        foreach ($data['materialMasuk'] ?? [] as $line) {
            $this->line($sheet, $row, [
                $line['description'], $line['received'], $line['rejected'], $line['unit'], $line['reason'],
            ]);
        }

        $row++;

        $this->line($sheet, $row, ['MATERIAL YANG DIPAKAI (DARI GUDANG)']);
        $this->line($sheet, $row, ['KODE ITEM', 'NAMA', 'QTY', 'SATUAN']);

        foreach ($data['materialsUsed'] ?? [] as $line) {
            $this->line($sheet, $row, [$line['code'], $line['name'], $line['qty'], $line['unit']]);
        }

        $row++;

        $this->line($sheet, $row, ['ALAT']);
        $this->line($sheet, $row, ['URAIAN', 'JUMLAH', 'JAM OPERASI']);

        foreach ($data['alat'] ?? [] as $line) {
            $this->line($sheet, $row, [$line['description'], $line['qty'], $line['hours']]);
        }

        $row++;

        $this->line($sheet, $row, ['URAIAN PEKERJAAN']);

        if (($data['uraianRows'] ?? []) === []) {
            // Layout warisan pra-P0-A: satu baris rangkuman bebas.
            $this->line($sheet, $row, [$data['activities'] ?? null]);
        } else {
            $this->line($sheet, $row, ['URAIAN', 'PROGRES', 'TARGET', 'KENDALA']);

            foreach ($data['uraianRows'] as $line) {
                $this->line($sheet, $row, [
                    $line['description'], $line['progress'], $line['target'], $line['obstacle'],
                ]);
            }
        }

        $row++;
    }

    // ----------------------------------------------------------------- notes

    private function writeNotes(Worksheet $sheet, int &$row, ?array $notes): void
    {
        if ($notes === null || ($notes['text'] ?? null) === null) {
            return;
        }

        $this->line($sheet, $row, ['CATATAN', $notes['text']]);
    }

    /**
     * Satu baris sel. String ditulis EKSPLISIT sebagai teks supaya "100,000"
     * hasil cast komposer tidak 'diperbaiki' binder menjadi angka lain;
     * angka mentah (float/int komposisi bespoke) ditulis sebagai angka; null
     * TIDAK ditulis sama sekali — sel kosong sungguhan.
     */
    private function line(Worksheet $sheet, int &$row, array $cells): void
    {
        $column = 1;

        foreach ($cells as $value) {
            if ($value !== null && $value !== '') {
                is_int($value) || is_float($value)
                    ? $sheet->getCell([$column, $row])->setValue($value)
                    : $sheet->getCell([$column, $row])->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }

            $column++;
        }

        $row++;
    }
}
