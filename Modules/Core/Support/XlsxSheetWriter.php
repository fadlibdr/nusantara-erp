<?php

namespace Modules\Core\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Aturan SEL untuk setiap XLSX yang ditulis sistem ini (Fase 1 / P1-F).
 *
 * Badan `putRow()` adalah badan `FormXlsxExportService::line()` yang sudah ada,
 * dipindahkan ke satu pemilik supaya penulis XLSX kedua (laporan bebas) tidak
 * menyalinnya — dan supaya aturan yang menjadi SYARAT paket ini punya tepat
 * satu tempat untuk salah:
 *
 *     if ($value !== null && $value !== '')
 *
 * Perbandingan KETAT, dengan sengaja. `empty($value)` dan `!= ''` keduanya
 * benar untuk int 0 dan float 0.0, jadi keduanya akan menulis sel KOSONG untuk
 * setiap nol yang sah — kebalikan persis dari aturan yang dijaga di sini.
 * Sebuah sel kosong berarti "tidak ada angkanya"; sebuah 0 berarti "saya
 * menghitung, dan hasilnya nol". Keduanya benar, dan menukarnya adalah
 * kebohongan ke arah yang berbeda.
 *
 * Angka ditulis sebagai ANGKA dan teks sebagai teks: `setValueExplicit` dengan
 * TYPE_STRING atas angka membuat Excel menampilkan angka rata kiri yang tidak
 * bisa dijumlahkan, dan itulah yang paling sering dikeluhkan tentang ekspor.
 */
final class XlsxSheetWriter
{
    /**
     * Satu baris sel, mulai kolom 1. `$row` maju satu.
     *
     * @param  list<mixed>  $cells
     */
    public static function putRow(Worksheet $sheet, int &$row, array $cells): void
    {
        foreach ($cells as $index => $value) {
            $column = $index + 1;

            if ($value !== null && $value !== '') {
                is_numeric($value) && ! is_string($value)
                    ? $sheet->getCell([$column, $row])->setValue($value)
                    : $sheet->getCell([$column, $row])->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        $row++;
    }

    /** Baris tebal — kepala tabel dan baris total. */
    public static function putHeadRow(Worksheet $sheet, int &$row, array $cells): void
    {
        $at = $row;
        self::putRow($sheet, $row, $cells);

        if ($cells !== []) {
            $sheet->getStyle([1, $at, count($cells), $at])->getFont()->setBold(true);
        }
    }
}
