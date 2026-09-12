<?php

namespace Modules\Finance\Support;

use InvalidArgumentException;

/**
 * Registri format berkas DJP/BPJS — SATU tempat yang menjawab "berkas ini
 * sudah dicocokkan dengan template resmi atau belum" (P-3b, T3b.0).
 *
 * MENGAPA ADA. TaxExportService membawa docblock "VERIFY THE LAYOUT BEFORE
 * PRODUCTION USE" sejak hari pertama, dan tidak seorang operator pun pernah
 * membacanya: yang sampai ke layar adalah tombol "Unduh CSV" dan berkas
 * bernama efaktur-2026-03.csv yang tampak selesai. Registri ini mengangkat
 * kalimat itu ke TIGA permukaan yang benar-benar dilihat orang — jawaban API
 * ikhtisar, lencana per format di layar Ekspor Pajak, dan nama + baris pertama
 * berkas yang diunduh — dari satu sumber, supaya tidak ada permukaan yang
 * berkata «sesuai DJP» sementara permukaan lain berkata «belum dicek».
 *
 * APA YANG TIDAK DIKARANG. verified_against hanya boleh menunjuk berkas
 * resmi yang benar-benar ada di docs/samples/pajak/ (diunduh pemilik atau
 * konsultan, bertanggal — lihat README di folder itu). Sebuah entri yang
 * MENYATAKAN verifikasi tetapi berkasnya tidak ada di pohon diturunkan
 * kembali menjadi "belum diverifikasi" oleh describe(): klaim tidak pernah
 * boleh mendahului buktinya, termasuk pada deploy yang lupa menyalin docs/.
 * Format berstatus "menunggu template" tidak punya writer dan tidak pernah
 * bisa diunduh; yang ia punya hanya kalimat yang menyebut berkas apa yang
 * harus diletakkan di docs/samples/pajak/.
 *
 * Writer legacy tidak disentuh: stampCsv() menambah SATU baris komentar di
 * atas berkas yang belum diverifikasi dan filename() menambah akhiran pada
 * namanya; kolom data berdiri persis di tempatnya. Berkas yang sudah
 * diverifikasi dikembalikan apa adanya — tata letak yang diverifikasi adalah
 * tata letak yang diunduh, tanpa satu baris tambahan pun.
 */
final class DjpFormats
{
    public const EFAKTUR_CSV_LEGACY = 'efaktur_csv_legacy';

    public const EFAKTUR_CORETAX_XML = 'efaktur_coretax_xml';

    public const EBUPOT_UNIFIKASI_CSV = 'ebupot_unifikasi_csv';

    public const EBUPOT_2126_BULANAN = 'ebupot_2126_bulanan';

    public const SIPP_BPJS = 'sipp_bpjs';

    public const STATUS_ADA = 'ada';

    public const STATUS_MENUNGGU_TEMPLATE = 'menunggu template';

    /** Folder berkas contoh resmi, relatif terhadap akar repo. */
    public const SAMPLES_DIR = 'docs/samples/pajak';

    public const README = self::SAMPLES_DIR.'/README.md';

    /** Awalan kalimat yang dipaku uji dan dicari harness — jangan diparafrasakan. */
    public const UNVERIFIED_PREFIX = 'BELUM DIVERIFIKASI terhadap template';

    /**
     * Kalimat yang menutup baris '#' di atas berkas yang belum diverifikasi, DAN
     * kalimat `file_note` yang dibaca layar (V3b-7): importer DJP mengharapkan
     * header di baris 1, jadi berkas unduhan tidak bisa diimpor apa adanya —
     * dan harus ada permukaan yang mengatakannya, bukan hanya penolakan importer.
     */
    public const FIRST_LINE_INSTRUCTION = 'Hapus baris pertama ini sebelum mengimpor — importer mengharapkan header di baris 1.';

    private const FILENAME_SUFFIX = '-belum-diverifikasi';

    /**
     * Deklarasi mentah. verified_against: null, atau ['path' => …, 'date' => 'YYYY-MM-DD']
     * yang menunjuk berkas di SAMPLES_DIR — diisi HANYA sesudah writer-nya
     * dicocokkan kolom demi kolom terhadap berkas itu dan konsultan mencatat
     * hasil sandbox di README §4.
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(): array
    {
        return [
            [
                'key' => self::EFAKTUR_CSV_LEGACY,
                'label' => 'e-Faktur CSV (skema impor desktop, FK/LT/OF)',
                'status' => self::STATUS_ADA,
                'authority' => 'DJP',
                'source' => 'Aplikasi e-Faktur desktop DJP — template impor faktur keluaran',
                'writer' => true,
                'verified_against' => null,
                'awaiting_file' => null,
            ],
            [
                'key' => self::EFAKTUR_CORETAX_XML,
                'label' => 'e-Faktur Coretax XML (faktur keluaran)',
                'status' => self::STATUS_MENUNGGU_TEMPLATE,
                'authority' => 'DJP',
                'source' => 'Portal Coretax DJP — template impor XML faktur keluaran',
                'writer' => false,
                'verified_against' => null,
                'awaiting_file' => 'template impor XML faktur keluaran dari Coretax',
            ],
            [
                'key' => self::EBUPOT_UNIFIKASI_CSV,
                'label' => 'e-Bupot Unifikasi CSV (PPh 23 / PPh final 4(2))',
                'status' => self::STATUS_ADA,
                'authority' => 'DJP',
                'source' => 'Coretax DJP — e-Bupot Unifikasi, template impor bukti potong',
                'writer' => true,
                'verified_against' => null,
                'awaiting_file' => null,
            ],
            [
                'key' => self::EBUPOT_2126_BULANAN,
                'label' => 'e-Bupot PPh 21/26 bulanan (pegawai tetap)',
                'status' => self::STATUS_MENUNGGU_TEMPLATE,
                'authority' => 'DJP',
                'source' => 'Coretax DJP — e-Bupot 21/26, template impor bukti potong bulanan',
                'writer' => false,
                'verified_against' => null,
                'awaiting_file' => 'template impor bukti potong PPh 21/26 bulanan dari Coretax',
            ],
            [
                'key' => self::SIPP_BPJS,
                'label' => 'SIPP Online BPJS Ketenagakerjaan (data upah/iuran)',
                'status' => self::STATUS_MENUNGGU_TEMPLATE,
                'authority' => 'BPJS Ketenagakerjaan',
                'source' => 'SIPP Online BPJS Ketenagakerjaan — template unggah data upah/iuran',
                'writer' => false,
                'verified_against' => null,
                'awaiting_file' => 'template unggah data upah/iuran dari SIPP Online (bila XLSX: keputusan phpspreadsheet, ledger #9)',
            ],
        ];
    }

    /**
     * Deklarasi mentah, sebelum describe() menurunkan apa pun — publik supaya uji
     * bisa memaku bahwa hari ini TIDAK ADA klaim verified_against sama sekali,
     * bukan hanya bahwa klaim yang salah sudah diturunkan (V2-4).
     *
     * @return list<array<string, mixed>>
     */
    public static function declared(): array
    {
        return self::entries();
    }

    /**
     * Semua entri, dijelaskan — kunci => entri.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::entries() as $entry) {
            $all[$entry['key']] = self::describe($entry);
        }

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        $all = self::all();

        if (! array_key_exists($key, $all)) {
            throw new InvalidArgumentException("Format DJP/BPJS [{$key}] tidak terdaftar di DjpFormats.");
        }

        return $all[$key];
    }

    /**
     * Daftar untuk jawaban API — urutan registri, tanpa field internal.
     *
     * @return list<array<string, mixed>>
     */
    public static function forApi(): array
    {
        return array_values(self::all());
    }

    public static function isVerified(string $key): bool
    {
        return (bool) self::get($key)['verified'];
    }

    /**
     * Lencana hitungan di kepala kartu layar — dari sini, bukan disusun SPA.
     *
     * @return array{total: int, verified: int, label: string}
     */
    public static function summary(): array
    {
        $all = self::all();
        $verified = count(array_filter($all, fn (array $entry): bool => (bool) $entry['verified']));

        return [
            'total' => count($all),
            'verified' => $verified,
            'label' => sprintf('%d dari %d diverifikasi terhadap template resmi', $verified, count($all)),
        ];
    }

    /** Nama berkas unduhan untuk sebuah format: akhiran jujur bila belum diverifikasi. */
    public static function filename(string $key, string $filename): string
    {
        return self::filenameFor(self::get($key), $filename);
    }

    /** Isi berkas unduhan: satu baris komentar di atas bila belum diverifikasi, apa adanya bila sudah. */
    public static function stampCsv(string $key, string $csv): string
    {
        return self::stampCsvFor(self::get($key), $csv);
    }

    /**
     * @param  array<string, mixed>  $entry  hasil describe()
     */
    public static function filenameFor(array $entry, string $filename): string
    {
        if ($entry['verified']) {
            return $filename;
        }

        $dot = strrpos($filename, '.');

        return $dot === false
            ? $filename.self::FILENAME_SUFFIX
            : substr($filename, 0, $dot).self::FILENAME_SUFFIX.substr($filename, $dot);
    }

    /**
     * @param  array<string, mixed>  $entry  hasil describe()
     */
    public static function stampCsvFor(array $entry, string $csv): string
    {
        if ($entry['verified']) {
            return $csv;
        }

        return '# '.self::UNVERIFIED_PREFIX.' '.$entry['authority']
            .' — tata letak kolom berkas ini belum dicocokkan dengan berkas contoh resmi; lihat '
            .self::README.' sebelum mengimpornya. '.self::FIRST_LINE_INSTRUCTION."\n".$csv;
    }

    /**
     * Fungsi MURNI dari deklarasi ke entri yang dibaca layar/API — publik supaya
     * kasus "verified_against menunjuk berkas yang tidak ada" bisa diuji tanpa
     * kait khusus-uji di registri.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function describe(array $entry): array
    {
        $authority = (string) $entry['authority'];
        $declared = $entry['verified_against'] ?? null;
        $stem = str_replace('_', '-', (string) $entry['key']);
        $verified = false;
        $against = null;

        if (is_array($declared) && isset($declared['path'], $declared['date'])) {
            $exists = is_file(base_path((string) $declared['path']));

            if ($exists) {
                $verified = true;
                $against = ['path' => (string) $declared['path'], 'date' => (string) $declared['date']];
                $verification = sprintf(
                    'Diverifikasi terhadap %s (%s) — cocokkan ulang bila %s menerbitkan template baru; catatan sandbox di %s §4.',
                    $declared['path'],
                    $declared['date'],
                    $authority,
                    self::README,
                );
            } else {
                $verification = sprintf(
                    '%s %s — registri menunjuk %s (%s) tetapi berkasnya tidak ada di pohon ini; letakkan berkas resminya sesuai %s.',
                    self::UNVERIFIED_PREFIX,
                    $authority,
                    $declared['path'],
                    $declared['date'],
                    self::README,
                );
            }
        } else {
            $verification = sprintf(
                '%s %s — belum ada berkas contoh resmi di %s/ untuk format ini; impor satu masa ke sandbox dan cocokkan totalnya sebelum dipakai melapor (%s).',
                self::UNVERIFIED_PREFIX,
                $authority,
                self::SAMPLES_DIR,
                self::README,
            );
        }

        $awaiting = null;

        if ($entry['status'] === self::STATUS_MENUNGGU_TEMPLATE) {
            $awaiting = sprintf(
                'Menunggu berkas %s/%s-<YYYY-MM-DD>.<ekstensi asli> — %s (diunduh pemilik/konsultan dari sumber resmi; lihat %s).',
                self::SAMPLES_DIR,
                $stem,
                (string) ($entry['awaiting_file'] ?? 'template resmi'),
                self::README,
            );
        }

        return [
            'key' => (string) $entry['key'],
            'label' => (string) $entry['label'],
            'status' => (string) $entry['status'],
            'status_label' => $entry['status'] === self::STATUS_ADA ? 'Ada' : 'Menunggu template',
            'authority' => $authority,
            'source' => (string) $entry['source'],
            'writer' => (bool) $entry['writer'],
            'downloadable' => (bool) $entry['writer'] && $entry['status'] === self::STATUS_ADA,
            'verified' => $verified,
            'verified_against' => $against,
            'verification' => $verification,
            // Teks lencana layar — dari sini, apa adanya. SPA yang menyusun kalimatnya
            // sendiri adalah satu permukaan lagi yang bisa berkata «sesuai DJP» tanpa
            // satu uji PHP pun merah (V3b-2/V2-5).
            'badge_label' => $verified
                ? sprintf('Diverifikasi %s', $against['date'])
                : sprintf('Belum diverifikasi terhadap template %s', $authority),
            'awaiting_file' => $awaiting,
            // Kalimat untuk kartu "Isi berkas" di layar — dari sini, bukan disusun SPA:
            // berkas yang belum diverifikasi dibuka oleh satu baris komentar '#'.
            'file_note' => $verified || ! ((bool) $entry['writer'] && $entry['status'] === self::STATUS_ADA)
                ? null
                : sprintf(
                    'Baris pertama berkas ini adalah komentar "# %s %s …". %s',
                    self::UNVERIFIED_PREFIX,
                    $authority,
                    self::FIRST_LINE_INSTRUCTION,
                ),
            'sample_stem' => $stem.'-<YYYY-MM-DD>',
        ];
    }
}
