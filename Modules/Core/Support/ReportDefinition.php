<?php

namespace Modules\Core\Support;

use InvalidArgumentException;

/**
 * Memvalidasi satu definisi Laporan Bebas terhadap katalog (Fase 1 / P1-F).
 *
 * Berkas ini adalah gerbangnya: yang lolos dari sini sudah TIDAK MUNGKIN
 * menyentuh kolom, tabel, agregat atau saringan di luar registri, sehingga
 * ReportRunner tidak perlu memeriksa apa pun lagi dan bisa dibaca sebagai
 * geometri murni.
 *
 * Dua sifat yang membuatnya berguna, keduanya dipinjam dari
 * ApiController::listing() yang sudah menolak `sort` yang tidak dikenal:
 *
 *  - **Penolakan MENYEBUT kuncinya, dan menyebut yang tersedia.** "Kolom tidak
 *    valid" pada layar dengan tujuh kolom tidak memberi tahu siapa pun kolom
 *    mana. Semua kalimat berbahasa Indonesia.
 *  - **Kolom yang DITOLAK katalog ditolak dengan ALASANNYA sendiri**, bukan
 *    dengan "tidak dikenal": `outstanding` ada di layar daftar, orang yang
 *    memintanya tidak sedang salah ketik, dan kalimat `why_not` registri
 *    menjelaskan mengapa satu kueri tidak bisa memproduksinya.
 *
 * Divalidasi ULANG setiap kali dijalankan, termasuk untuk laporan tersimpan:
 * katalog berubah lebih cepat daripada baris tersimpan, dan sebuah kolom yang
 * dicabut harus membuat laporan lama berkata "kolom X sudah tidak ada" alih-alih
 * menjalankan kueri dengan kolom yang tidak ada lagi.
 */
final class ReportDefinition
{
    public const MODES = ['detail', 'group', 'pivot'];

    public const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count'];

    /** Kolom maksimum pada mode rincian — selebar tabel yang masih bisa dibaca. */
    public const MAX_DETAIL_COLUMNS = 12;

    /** Nilai maksimum satu saringan `in`; MySQL menolak > 65.535 placeholder. */
    public const MAX_IN_VALUES = 50;

    /**
     * @param  array<string, mixed>  $input  definisi mentah dari klien atau dari baris tersimpan
     * @return array<string, mixed> definisi yang sudah tervalidasi
     *
     * @throws InvalidArgumentException dengan kalimat yang dibaca pengguna
     */
    public static function validate(array $input): array
    {
        $resource = $input['resource'] ?? null;

        if (! is_string($resource) || ! ReportableResources::has($resource)) {
            throw new InvalidArgumentException(sprintf(
                'Sumber laporan "%s" tidak ada di katalog. Sumber yang tersedia: %s.',
                is_string($resource) ? $resource : '(kosong)',
                implode(', ', ReportableResources::keys()),
            ));
        }

        $entry = ReportableResources::definition($resource);
        $mode = $input['mode'] ?? null;

        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Mode laporan "%s" tidak dikenal. Mode yang tersedia: %s.',
                is_string($mode) ? $mode : '(kosong)',
                implode(', ', self::MODES),
            ));
        }

        $out = ['resource' => $resource, 'mode' => $mode];

        if ($mode === 'detail') {
            $out['columns'] = self::validateDetailColumns($entry, $input['columns'] ?? null);
        } else {
            $out['row'] = self::validateDimension($entry, $input['row'] ?? null, 'baris');
            $out['measure'] = self::validateMeasure($entry, $input['measure'] ?? null);

            if ($mode === 'pivot') {
                $out['column'] = self::validateDimension($entry, $input['column'] ?? null, 'kolom');

                if ($out['column']['column'] === $out['row']['column']) {
                    throw new InvalidArgumentException(sprintf(
                        'Dimensi baris dan dimensi kolom tidak boleh kolom yang sama ("%s") — hasilnya satu sel '
                        .'per baris dan tidak membandingkan apa pun.',
                        $out['row']['column'],
                    ));
                }
            }
        }

        $out['filters'] = self::validateFilters($entry, $input['filters'] ?? []);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function validateDetailColumns(array $entry, mixed $columns): array
    {
        if (! is_array($columns) || ! array_is_list($columns) || $columns === []) {
            throw new InvalidArgumentException('Laporan rincian butuh sedikitnya satu kolom.');
        }

        if (count($columns) > self::MAX_DETAIL_COLUMNS) {
            throw new InvalidArgumentException(sprintf('Maksimal %d kolom pada laporan rincian.', self::MAX_DETAIL_COLUMNS));
        }

        $out = [];

        foreach ($columns as $key) {
            $column = self::column($entry, $key, 'Kolom');

            if (! isset($column['select'])) {
                throw new InvalidArgumentException(sprintf('Kolom "%s" tidak dapat dilaporkan. %s', $key, $column['why_not']));
            }

            if (in_array($key, $out, true)) {
                throw new InvalidArgumentException(sprintf('Kolom "%s" disebut dua kali.', $key));
            }

            $out[] = $key;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{column: string, bucket?: string}
     */
    private static function validateDimension(array $entry, mixed $dimension, string $noun): array
    {
        if (! is_array($dimension) || ! isset($dimension['column'])) {
            throw new InvalidArgumentException(sprintf('Dimensi %s belum dipilih.', $noun));
        }

        $key = $dimension['column'];
        $column = self::column($entry, $key, sprintf('Dimensi %s', $noun));

        if (($column['dimension'] ?? false) === false) {
            throw new InvalidArgumentException(sprintf(
                'Kolom "%s" tidak bisa menjadi dimensi %s. %s',
                $key, $noun, $column['why_not'] ?? '',
            ));
        }

        $out = ['column' => $key];

        if ($column['dimension'] === 'date') {
            $bucket = $dimension['bucket'] ?? 'month';
            $allowed = $column['buckets'] ?? [];

            if (! is_string($bucket) || ! in_array($bucket, $allowed, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Satuan periode "%s" tidak dikenal untuk kolom "%s". Yang tersedia: %s.',
                    is_string($bucket) ? $bucket : '(kosong)', $key, implode(', ', $allowed),
                ));
            }

            $out['bucket'] = $bucket;
        } elseif (isset($dimension['bucket'])) {
            throw new InvalidArgumentException(sprintf('Kolom "%s" bukan tanggal, jadi ia tidak punya satuan periode.', $key));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{agg: string, column?: string}
     */
    private static function validateMeasure(array $entry, mixed $measure): array
    {
        if (! is_array($measure) || ! isset($measure['agg'])) {
            throw new InvalidArgumentException('Ukuran laporan belum dipilih.');
        }

        $agg = $measure['agg'];

        if (! is_string($agg) || ! in_array($agg, self::AGGREGATES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Agregat "%s" tidak dikenal. Yang tersedia: %s.',
                is_string($agg) ? $agg : '(kosong)', implode(', ', self::AGGREGATES),
            ));
        }

        // count tanpa kolom = count(*), satu-satunya ukuran yang tidak butuh kolom.
        if ($agg === 'count' && ! isset($measure['column'])) {
            return ['agg' => 'count'];
        }

        $key = $measure['column'] ?? null;
        $column = self::column($entry, $key, 'Kolom ukuran');

        if (($column['measure'] ?? false) !== true) {
            throw new InvalidArgumentException(sprintf(
                'Kolom "%s" tidak bisa dijadikan ukuran. %s',
                $key, $column['why_not'] ?? 'Hanya kolom uang yang penjumlahannya berarti.',
            ));
        }

        return ['agg' => $agg, 'column' => $key];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function validateFilters(array $entry, mixed $filters): array
    {
        if (! is_array($filters)) {
            throw new InvalidArgumentException('Saringan harus berupa objek.');
        }

        $out = [];

        foreach (['date_from', 'date_to'] as $key) {
            if (! isset($filters[$key])) {
                continue;
            }

            if ($entry['date_column'] === null) {
                throw new InvalidArgumentException(
                    'Sumber ini tidak menawarkan jendela tanggal — layar daftarnya pun tidak, karena tidak ada satu '
                    .'kolom tanggal yang mewakili barisnya.',
                );
            }

            $value = $filters[$key];

            // Format DIPAKU, bukan diparse bebas: string sembarang yang lolos ke
            // whereDate mengubah tautan karangan menjadi 500 (aturan listing()).
            if (! is_string($value) || ! self::isDate($value)) {
                throw new InvalidArgumentException(sprintf('Tanggal "%s" harus berbentuk YYYY-MM-DD.', $key));
            }

            $out[$key] = $value;
        }

        if (isset($out['date_from'], $out['date_to']) && $out['date_from'] > $out['date_to']) {
            throw new InvalidArgumentException('Tanggal awal jendela melewati tanggal akhirnya.');
        }

        foreach (['eq', 'in'] as $shape) {
            if (! isset($filters[$shape])) {
                continue;
            }

            if (! is_array($filters[$shape])) {
                throw new InvalidArgumentException(sprintf('Saringan "%s" harus berupa objek.', $shape));
            }

            foreach ($filters[$shape] as $key => $value) {
                if (! isset($entry['filters'][$key])) {
                    throw new InvalidArgumentException(sprintf(
                        'Saringan "%s" tidak tersedia untuk sumber ini. Yang tersedia: %s.',
                        $key, implode(', ', array_keys($entry['filters'])),
                    ));
                }

                if ($shape === 'in') {
                    if (! is_array($value) || ! array_is_list($value) || $value === []) {
                        throw new InvalidArgumentException(sprintf('Saringan "%s" butuh sedikitnya satu nilai.', $key));
                    }

                    if (count($value) > self::MAX_IN_VALUES) {
                        throw new InvalidArgumentException(sprintf(
                            'Saringan "%s" menyebut lebih dari %d nilai.', $key, self::MAX_IN_VALUES,
                        ));
                    }
                } elseif (is_array($value)) {
                    throw new InvalidArgumentException(sprintf('Saringan "%s" hanya menerima satu nilai.', $key));
                }

                $out[$shape][$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function column(array $entry, mixed $key, string $noun): array
    {
        if (! is_string($key) || ! isset($entry['columns'][$key])) {
            throw new InvalidArgumentException(sprintf(
                '%s "%s" tidak ada di sumber ini. Kolom yang tersedia: %s.',
                $noun,
                is_string($key) ? $key : '(kosong)',
                implode(', ', array_keys($entry['columns'])),
            ));
        }

        return $entry['columns'][$key];
    }

    private static function isDate(string $value): bool
    {
        $parts = explode('-', $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}
