<?php

namespace Modules\Finance\Support;

use Carbon\CarbonImmutable;
use Modules\Finance\Enums\BankStatementFormat;

/**
 * Bentuk preset impor per rekening (fin_bank_accounts.import_preset, P-3c) —
 * fungsi murni atas array, tanpa basis data, supaya setiap aturannya bisa
 * dipaku dengan array buatan.
 *
 * PILIHAN EKSPLISIT, BUKAN SNIFFING. Preset hanya menyimpan pemetaan KOLOM
 * (bukan periode/saldo — itu milik tiap berkas) dan SEL BARIS JUDUL pada
 * kolom yang dipetakan. Bulan berikutnya, berkas yang judul kolomnya bergeser
 * ditolak dengan kalimat yang menyebut kolomnya: "Kolom 4 pada preset «BCA
 * KlikBCA» diharapkan 'Debit', berkas berisi 'Mutasi'." — bukan diimpor
 * keliru-tetapi-seimbang. Nomor kolom di kalimat itu 1-based, persis label
 * "Kolom N" yang dilihat operator di layar (indeks yang tersimpan 0-based,
 * seperti yang dikirim SPA).
 */
final class ImportPreset
{
    /** Kunci pemetaan yang DISIMPAN preset — periode/saldo sengaja tidak ada. */
    public const MAPPING_KEYS = [
        'delimiter', 'skip_rows', 'date_column', 'date_format', 'description_column', 'reference_column',
        'balance_column', 'amount_mode', 'debit_column', 'credit_column', 'amount_column', 'indicator_column',
        'number_format',
    ];

    /** Kunci yang tetap diketik operator (layar) atau diturunkan dari berkas (folder terpantau). */
    public const PER_FILE_KEYS = ['period_start', 'period_end', 'opening_balance', 'closing_balance'];

    private const COLUMN_KEYS = [
        'date_column', 'description_column', 'reference_column', 'balance_column',
        'debit_column', 'credit_column', 'amount_column', 'indicator_column',
    ];

    public const HEADER_NOTE_NONE = 'Berkas tanpa baris judul: pergeseran kolom tidak bisa dideteksi dari judulnya — periksa pratinjau setiap kali.';

    /**
     * @param  array<string, mixed>  $mapping  pemetaan layar (boleh memuat periode/saldo — dibuang)
     * @return array<string, mixed>
     */
    public static function mappingOnly(array $mapping): array
    {
        $kept = [];

        foreach (self::MAPPING_KEYS as $key) {
            if (array_key_exists($key, $mapping) && $mapping[$key] !== null && $mapping[$key] !== '') {
                $kept[$key] = $mapping[$key];
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return list<int> indeks kolom (0-based) yang dipetakan, unik, urut
     */
    public static function mappedColumns(array $mapping): array
    {
        $columns = [];

        foreach (self::COLUMN_KEYS as $key) {
            if (isset($mapping[$key]) && $mapping[$key] !== '') {
                $columns[] = (int) $mapping[$key];
            }
        }

        $columns = array_values(array_unique($columns));
        sort($columns);

        return $columns;
    }

    /**
     * Sel baris judul pada kolom yang dipetakan, dari baris judul yang
     * DILEWATI parser (baris fisik ke-skip_rows). null bila preset tidak
     * punya baris judul (skip_rows 0) atau barisnya tidak ada.
     *
     * DAFTAR objek {index, cell}, BUKAN peta berindeks angka: JsonResource
     * Laravel (BankAccountResource) menjalankan array_values() atas array
     * berkunci numerik, sehingga {"0","1","3"} sampai ke layar sebagai
     * ["…","…","…"] dan kartu preset menulis "Kolom 3 'Debit'" untuk kolom 4
     * — terukur di Chromium (harness S39) sebelum bentuk ini diganti.
     *
     * @param  list<string>|null  $headerRow
     * @param  array<string, mixed>  $mapping
     * @return list<array{index: int, cell: string}>|null
     */
    public static function expectedHeader(?array $headerRow, array $mapping): ?array
    {
        if ($headerRow === null) {
            return null;
        }

        $expected = [];

        foreach (self::mappedColumns($mapping) as $index) {
            $expected[] = ['index' => $index, 'cell' => trim((string) ($headerRow[$index] ?? ''))];
        }

        return $expected;
    }

    /**
     * @param  array<string, mixed>  $mapping  pemetaan layar
     * @param  list<string>|null  $headerRow
     * @return array<string, mixed>
     */
    public static function build(string $name, array $mapping, ?array $headerRow, ?int $userId, ?CarbonImmutable $now = null): array
    {
        $mappingOnly = self::mappingOnly($mapping);
        $expected = self::expectedHeader($headerRow, $mappingOnly);

        return [
            'name' => trim($name),
            'format' => BankStatementFormat::Csv->value,
            'mapping' => $mappingOnly,
            'expected_header' => $expected,
            'header_note' => $expected === null ? self::HEADER_NOTE_NONE : null,
            'saved_at' => ($now ?? CarbonImmutable::now())->toIso8601String(),
            'saved_by' => $userId,
        ];
    }

    /**
     * Kalimat per kolom yang judulnya bergeser — kosong bila cocok (atau bila
     * preset tidak mengingat judul). Semua kolom disebut, bukan hanya yang
     * pertama: operator memperbaiki berkas atau presetnya sekali, bukan
     * satu 422 per kolom.
     *
     * @param  array<string, mixed>  $preset
     * @param  list<string>|null  $headerRow  baris fisik ke-skip_rows dari berkas yang diperiksa
     * @return list<string>
     */
    public static function headerMismatches(array $preset, ?array $headerRow): array
    {
        $expected = $preset['expected_header'] ?? null;

        if (! is_array($expected) || $expected === []) {
            return [];
        }

        $name = (string) ($preset['name'] ?? '');
        $sentences = [];

        foreach ($expected as $entry) {
            if (! is_array($entry) || ! isset($entry['index'])) {
                continue;
            }

            $index = (int) $entry['index'];
            $cell = (string) ($entry['cell'] ?? '');
            $actual = $headerRow === null ? '' : trim((string) ($headerRow[$index] ?? ''));

            if ($actual !== $cell) {
                $sentences[] = sprintf(
                    "Kolom %d pada preset «%s» diharapkan '%s', berkas berisi '%s'.",
                    $index + 1,
                    $name,
                    $cell,
                    $actual,
                );
            }
        }

        return $sentences;
    }

    /**
     * Pemetaan lengkap untuk parser: kolom dari preset + periode/saldo per berkas.
     *
     * @param  array<string, mixed>  $preset
     * @param  array<string, mixed>  $perFile  boleh memuat kunci lain — hanya PER_FILE_KEYS yang diambil
     * @return array<string, mixed>
     */
    public static function merge(array $preset, array $perFile): array
    {
        $mapping = self::mappingOnly((array) ($preset['mapping'] ?? []));

        foreach (self::PER_FILE_KEYS as $key) {
            if (array_key_exists($key, $perFile) && $perFile[$key] !== null && $perFile[$key] !== '') {
                $mapping[$key] = $perFile[$key];
            }
        }

        return $mapping;
    }

    /** Preset CSV memetakan kolom saldo — syarat impor tanpa operator (folder terpantau). */
    public static function hasBalanceColumn(array $preset): bool
    {
        $mapping = (array) ($preset['mapping'] ?? []);

        return isset($mapping['balance_column']) && $mapping['balance_column'] !== '';
    }
}
